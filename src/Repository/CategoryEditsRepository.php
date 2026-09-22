<?php

declare( strict_types = 1 );

namespace App\Repository;

use App\Helper\AutomatedEditsHelper;
use App\Model\Edit;
use App\Model\Project;
use App\Model\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Wikimedia\IPUtils;

/**
 * CategoryEditsRepository is responsible for retrieving data from the database
 * about the edits made by a user to pages in a set of given categories.
 */
class CategoryEditsRepository extends Repository {
	/** @var int Revisions returned per page of contributions. */
	private const REVISION_LIMIT = 50;

	/** @var int Page IDs bound per statement when an ID list has to be chunked. */
	private const ID_CHUNK_SIZE = 10000;

	/**
	 * @var int Page IDs per statement for the revision-rows query. Deliberately large: each
	 *   chunk re-walks the user's revision history from the top, so one statement is the norm.
	 */
	private const ROW_CHUNK_SIZE = 50000;

	/**
	 * @var int At or below this many revisions in range, enumerating the user's edits is cheap
	 *   whatever the categories look like, and the category probe is skipped entirely.
	 */
	private const USER_PROBE_CAP = 10000;

	/** @var int At or below this many members, the categories are small enough to drive. */
	private const CATEGORY_PROBE_CAP = 20000;

	/** @var array<string, string[]> Per-request memo of linktarget lookups, keyed db|categories. */
	private array $categoryTargetIds = [];

	/**
	 * @param ManagerRegistry $managerRegistry
	 * @param CacheItemPoolInterface $cache
	 * @param Client $guzzle
	 * @param LoggerInterface $logger
	 * @param ParameterBagInterface $parameterBag
	 * @param bool $isWMF
	 * @param int $queryTimeout
	 * @param AutomatedEditsHelper $autoEditsHelper
	 * @param EditRepository $editRepo
	 * @param PageRepository $pageRepo
	 * @param UserRepository $userRepo
	 */
	public function __construct(
		protected ManagerRegistry $managerRegistry,
		protected CacheItemPoolInterface $cache,
		protected Client $guzzle,
		protected LoggerInterface $logger,
		protected ParameterBagInterface $parameterBag,
		protected bool $isWMF,
		protected int $queryTimeout,
		protected AutomatedEditsHelper $autoEditsHelper,
		protected EditRepository $editRepo,
		protected PageRepository $pageRepo,
		protected UserRepository $userRepo
	) {
		parent::__construct( $managerRegistry, $cache, $guzzle, $logger, $parameterBag, $isWMF, $queryTimeout );
	}

	/**
	 * Get the number of edits this user made to the given categories.
	 * @param Project $project
	 * @param User $user
	 * @param string[] $categories
	 * @param int|false $start Start date as Unix timestamp.
	 * @param int|false $end End date as Unix timestamp.
	 * @return int Result of query, see below.
	 */
	public function countCategoryEdits(
		Project $project,
		User $user,
		array $categories,
		int|false $start = false,
		int|false $end = false
	): int {
		$cacheKey = $this->getCacheKey( func_get_args(), 'user_categoryeditcount' );
		if ( $this->cache->hasItem( $cacheKey ) ) {
			return $this->cache->getItem( $cacheKey )->get();
		}

		// Every revision belongs to exactly one page, so summing the per-page counts over the
		// matched pages is the same number the old COUNT(DISTINCT rev_id) produced. A page in
		// two of the given categories still contributes its edits once: the duplication lives
		// in the membership map, not in the counts.
		$intersection = $this->getCategoryPageEdits( $project, $user, $categories, $start, $end );
		$result = array_sum( $intersection['counts'] );

		// Cache and return.
		return $this->setCache( $cacheKey, $result );
	}

	/**
	 * Get number of edits within each individual category.
	 * @param Project $project
	 * @param User $user
	 * @param array $categories
	 * @param int|false $start
	 * @param int|false $end
	 * @return string[] With categories as keys, counts as values.
	 */
	public function getCategoryCounts(
		Project $project,
		User $user,
		array $categories,
		int|false $start = false,
		int|false $end = false
	): array {
		$cacheKey = $this->getCacheKey( func_get_args(), 'user_categorycounts' );
		if ( $this->cache->hasItem( $cacheKey ) ) {
			return $this->cache->getItem( $cacheKey )->get();
		}

		$intersection = $this->getCategoryPageEdits( $project, $user, $categories, $start, $end );
		$titles = $this->getCategoryTargetIds( $project, $categories );

		$counts = [];
		foreach ( $intersection['membership'] as $targetId => $pageIds ) {
			$editCount = 0;
			foreach ( $pageIds as $pageId ) {
				$editCount += $intersection['counts'][$pageId];
			}
			// The old query INNER JOINed, so a category the user never edited in produced no
			// row at all. The template counts the array to say "in N categories", so a
			// zero-count entry here would change what the page reports.
			if ( $editCount === 0 ) {
				continue;
			}
			$counts[$titles[$targetId]] = [
				'editCount' => $editCount,
				'pageCount' => count( $pageIds ),
			];
		}

		// Reproduce the old ORDER BY edit_count DESC: the result template renders these in
		// iteration order and feeds the same order to a chart. Ties break on the category name
		// so the order is stable rather than dependent on the order rows came back in.
		uksort( $counts, static function ( string $a, string $b ) use ( $counts ): int {
			return $counts[$b]['editCount'] <=> $counts[$a]['editCount'] ?: strcmp( $a, $b );
		} );

		// Cache and return.
		return $this->setCache( $cacheKey, $counts );
	}

	/**
	 * Get contributions made to the given categories.
	 * @param Project $project
	 * @param User $user
	 * @param string[] $categories
	 * @param int|false $start Start date as Unix timestamp.
	 * @param int|false $end End date as Unix timestamp.
	 * @param false|int $offset Unix timestamp. Used for pagination.
	 * @return string[] Result of query, with columns 'page_title', 'namespace', 'rev_id', 'timestamp', 'minor',
	 *   'length', 'length_change', 'comment'
	 */
	public function getCategoryEdits(
		Project $project,
		User $user,
		array $categories,
		int|false $start = false,
		int|false $end = false,
		int|false $offset = false
	): array {
		$cacheKey = $this->getCacheKey( func_get_args(), 'user_categoryedits' );
		if ( $this->cache->hasItem( $cacheKey ) ) {
			return $this->cache->getItem( $cacheKey )->get();
		}

		// The intersection is the same whatever page of results we're on, so it's computed
		// (and cached) without $offset, and the offset is applied to the rows query alone.
		$intersection = $this->getCategoryPageEdits( $project, $user, $categories, $start, $end );
		$pageIds = array_keys( $intersection['counts'] );
		if ( $pageIds === [] ) {
			return $this->setCache( $cacheKey, [] );
		}

		$pageTable = $project->getTableName( 'page' );
		$revisionTable = $project->getTableName( 'revision' );
		$commentTable = $project->getTableName( 'comment', 'revision' );
		$revDateConditions = $this->getDateConditions( $start, $end, $offset, 'revs.' );

		$rows = [];
		foreach ( array_chunk( $pageIds, self::ROW_CHUNK_SIZE ) as $chunk ) {
			$qb = $this->wikiQueryBuilder( $project );
			$qb->select(
					'page_title',
					'page_namespace AS `namespace`',
					'revs.rev_id AS `rev_id`',
					'revs.rev_timestamp AS `timestamp`',
					'revs.rev_minor_edit AS `minor`',
					'revs.rev_len AS `length`',
					'(CAST(revs.rev_len AS SIGNED) - IFNULL(parentrevs.rev_len, 0)) AS `length_change`',
					'comment_text AS `comment`'
				)
				->from( $pageTable )
				->join( $pageTable, $revisionTable, 'revs', 'page_id = revs.rev_page' );
			$this->applyUserFilter( $qb, $project, $user );
			$qb->leftJoin( 'revs', $commentTable, 'comment', 'revs.rev_comment_id = comment_id' )
				->leftJoin( 'revs', $revisionTable, 'parentrevs', 'revs.rev_parent_id = parentrevs.rev_id' )
				->andWhere( 'revs.rev_page IN (:pageIds)' )
				->setParameter( 'pageIds', $chunk, ArrayParameterType::INTEGER )
				// No GROUP BY rev_id: it existed only to collapse the categorylinks fan-out,
				// and every join left here is to a unique key, so there's one row per revision.
				// rev_id breaks timestamp ties so chunks merge deterministically below.
				->orderBy( 'revs.rev_timestamp', 'DESC' )
				->addOrderBy( 'revs.rev_id', 'DESC' )
				->setMaxResults( self::REVISION_LIMIT );
			$qb->andWhere( '1 = 1' . $revDateConditions );

			$rows = array_merge( $rows, $this->executeQueryBuilder( $qb, $project )->fetchAllAssociative() );
		}

		// Any revision in the global top 50 is in its own chunk's top 50 as well: at most 49
		// revisions precede it globally, so at most 49 precede it within its chunk. Sorting the
		// union and taking 50 therefore gives exactly what one unchunked query would have.
		usort( $rows, static fn ( array $a, array $b ): int =>
			[ $b['timestamp'], $b['rev_id'] ] <=> [ $a['timestamp'], $a['rev_id'] ] );
		$result = array_slice( $rows, 0, self::REVISION_LIMIT );

		// Cache and return.
		return $this->setCache( $cacheKey, $result );
	}

	/**
	 * The pages that are in at least one of the given categories AND were edited by the user in
	 * the date range, with how many times the user edited each and which of the categories it
	 * belongs to.
	 *
	 * The two halves live on different database sections once a wiki's links tables are split
	 * out (Commons, T398709), so they can no longer be one JOIN. Whichever half is cheaper to
	 * enumerate drives, and its page IDs constrain the other.
	 *
	 * @param Project $project
	 * @param User $user
	 * @param string[] $categories
	 * @param int|false $start
	 * @param int|false $end
	 * @return array{counts:array<int,int>,membership:array<int,int[]>} 'counts' is page ID =>
	 *   number of edits; 'membership' is category target ID => page IDs, both restricted to
	 *   pages that appear in the intersection.
	 */
	private function getCategoryPageEdits(
		Project $project,
		User $user,
		array $categories,
		int|false $start,
		int|false $end
	): array {
		$cacheKey = $this->getCacheKey( func_get_args(), 'user_category_page_edits' );
		if ( $this->cache->hasItem( $cacheKey ) ) {
			return $this->cache->getItem( $cacheKey )->get();
		}

		$empty = [ 'counts' => [], 'membership' => [] ];
		$catTargetIds = array_keys( $this->getCategoryTargetIds( $project, $categories ) );
		if ( $catTargetIds === [] ) {
			// None of the given categories exist. Without this the IN list below would be
			// empty, which Doctrine renders as IN (NULL).
			return $this->setCache( $cacheKey, $empty );
		}

		$revCount = $this->probeUserEditCount( $project, $user, $start, $end );
		if ( $revCount === 0 ) {
			// The user made no edits in range at all, so no category query can match anything.
			return $this->setCache( $cacheKey, $empty );
		}

		if ( $revCount <= self::USER_PROBE_CAP ) {
			// Few enough edits that enumerating them is trivially cheap, whatever the categories
			// look like. Skip the category probe, which is the more expensive of the two.
			$result = $this->intersectUserFirst( $project, $user, $catTargetIds, $start, $end );
		} elseif ( $this->probeCategorySize( $project, $catTargetIds ) <= self::CATEGORY_PROBE_CAP ) {
			$result = $this->intersectCategoryFirst( $project, $user, $catTargetIds, $start, $end );
		} else {
			$result = $this->intersectUserFirst( $project, $user, $catTargetIds, $start, $end );
		}

		return $this->setCache( $cacheKey, $result );
	}

	/**
	 * Enumerate the user's edited pages, then ask the links section which of them are in the
	 * categories. The user side is bounded by APP_MAX_USER_EDITS, which the controller enforces
	 * before we get here.
	 * @return array{counts:array<int,int>,membership:array<int,int[]>}
	 */
	private function intersectUserFirst(
		Project $project,
		User $user,
		array $catTargetIds,
		int|false $start,
		int|false $end
	): array {
		$userCounts = $this->fetchUserPageCounts( $project, $user, $start, $end, null );
		if ( $userCounts === [] ) {
			return [ 'counts' => [], 'membership' => [] ];
		}

		$categorylinksTable = $this->getLinksTableName( $project, 'categorylinks' );
		$membership = [];
		$matched = [];
		$linkRows = 0;

		foreach ( array_chunk( array_keys( $userCounts ), self::ID_CHUNK_SIZE ) as $chunk ) {
			$qb = $this->linksQueryBuilder( $project );
			$qb->select( 'cl_target_id', 'cl_from' )
				->from( $categorylinksTable )
				// Hits PRIMARY (cl_from, cl_target_id), so this is a run of key probes.
				->where( 'cl_from IN (:pageIds)' )
				->andWhere( 'cl_target_id IN (:catIds)' )
				->setParameter( 'pageIds', $chunk, ArrayParameterType::INTEGER )
				->setParameter( 'catIds', $catTargetIds, ArrayParameterType::INTEGER );

			$stmt = $this->executeQueryBuilder( $qb, $this->getLinksSlice( $project ) );
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( $row = $stmt->fetchAssociative() ) {
				$this->assertLinksLimit( ++$linkRows );
				$pageId = (int)$row['cl_from'];
				$membership[(int)$row['cl_target_id']][] = $pageId;
				$matched[$pageId] = true;
			}
		}

		return [
			'counts' => array_intersect_key( $userCounts, $matched ),
			'membership' => $membership,
		];
	}

	/**
	 * Enumerate the categories' members, then ask the wiki section how many times the user
	 * edited each. Only worth it when the category side is genuinely small; see
	 * getCategoryPageEdits().
	 * @return array{counts:array<int,int>,membership:array<int,int[]>}
	 */
	private function intersectCategoryFirst(
		Project $project,
		User $user,
		array $catTargetIds,
		int|false $start,
		int|false $end
	): array {
		$categorylinksTable = $this->getLinksTableName( $project, 'categorylinks' );
		$qb = $this->linksQueryBuilder( $project );
		$qb->select( 'cl_target_id', 'cl_from' )
			->from( $categorylinksTable )
			->where( 'cl_target_id IN (:catIds)' )
			->setParameter( 'catIds', $catTargetIds, ArrayParameterType::INTEGER );

		$membershipAll = [];
		$pageIds = [];
		$linkRows = 0;
		$stmt = $this->executeQueryBuilder( $qb, $this->getLinksSlice( $project ) );
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( $row = $stmt->fetchAssociative() ) {
			$this->assertLinksLimit( ++$linkRows );
			$pageId = (int)$row['cl_from'];
			$membershipAll[(int)$row['cl_target_id']][] = $pageId;
			$pageIds[$pageId] = true;
		}
		if ( $pageIds === [] ) {
			return [ 'counts' => [], 'membership' => [] ];
		}

		$counts = [];
		foreach ( array_chunk( array_keys( $pageIds ), self::ID_CHUNK_SIZE ) as $chunk ) {
			$counts += $this->fetchUserPageCounts( $project, $user, $start, $end, $chunk );
		}

		// Drop the members the user never edited, so both halves describe the intersection.
		$membership = [];
		foreach ( $membershipAll as $targetId => $members ) {
			$kept = array_values( array_filter( $members,
				static fn ( int $pageId ): bool => isset( $counts[$pageId] ) ) );
			if ( $kept !== [] ) {
				$membership[$targetId] = $kept;
			}
		}

		return [ 'counts' => $counts, 'membership' => $membership ];
	}

	/**
	 * How many times the user edited each page, optionally restricted to the given page IDs.
	 * Lives entirely on the wiki section: ip_changes sits with revision, so the IP-range variant
	 * is unaffected by the links split.
	 * @param Project $project
	 * @param User $user
	 * @param int|false $start
	 * @param int|false $end
	 * @param int[]|null $pageIds Restrict to these pages, or null for every page the user edited.
	 * @return array<int,int> Page ID => edit count.
	 */
	private function fetchUserPageCounts(
		Project $project,
		User $user,
		int|false $start,
		int|false $end,
		?array $pageIds
	): array {
		$revisionTable = $project->getTableName( 'revision' );
		$qb = $this->wikiQueryBuilder( $project );
		$qb->select( 'revs.rev_page', 'COUNT(*) AS edit_count' )
			->from( $revisionTable, 'revs' );
		$this->applyUserFilter( $qb, $project, $user );
		if ( $pageIds !== null ) {
			$qb->andWhere( 'revs.rev_page IN (:pageIds)' )
				->setParameter( 'pageIds', $pageIds, ArrayParameterType::INTEGER );
		}
		$qb->andWhere( '1 = 1' . $this->getDateConditions( $start, $end, false, 'revs.' ) )
			->groupBy( 'revs.rev_page' );

		return array_map( 'intval', $this->executeQueryBuilder( $qb, $project )->fetchAllKeyValue() );
	}

	/**
	 * How many revisions the user made in range, capped: we only need to know which side of
	 * USER_PROBE_CAP it falls on, and an uncapped COUNT would scan the user's whole history.
	 * The LIMIT keeps it to a bounded walk of a covering index.
	 * @return int At most USER_PROBE_CAP + 1.
	 */
	private function probeUserEditCount(
		Project $project,
		User $user,
		int|false $start,
		int|false $end
	): int {
		$limit = self::USER_PROBE_CAP + 1;
		if ( $user->isIpRange() ) {
			// ipc_hex_time (ipc_hex, ipc_rev_timestamp) covers this.
			$ipcTable = $project->getTableName( 'ip_changes' );
			[ $hexStart, $hexEnd ] = IPUtils::parseRange( $user->getUsername() );
			$dates = $this->getDateConditions( $start, $end, false, '', 'ipc_rev_timestamp' );
			$inner = "SELECT 1 FROM $ipcTable WHERE ipc_hex BETWEEN :hexStart AND :hexEnd $dates LIMIT $limit";
			$params = [ 'hexStart' => $hexStart, 'hexEnd' => $hexEnd ];
		} else {
			// rev_actor_timestamp (rev_actor, rev_timestamp, rev_id) covers this.
			$revisionTable = $project->getTableName( 'revision' );
			$dates = $this->getDateConditions( $start, $end, false, 'revs.' );
			$inner = "SELECT 1 FROM $revisionTable revs WHERE revs.rev_actor = :actorId $dates LIMIT $limit";
			$params = [ 'actorId' => $user->getActorId( $project ) ];
		}

		return (int)$this->executeProjectsQuery( $project, "SELECT COUNT(*) FROM ( $inner ) probe", $params )
			->fetchOne();
	}

	/**
	 * How many pages are in the given categories, capped the same way and for the same reason as
	 * probeUserEditCount(): an uncapped COUNT over a Commons maintenance category is a scan of
	 * millions of index entries, on the very host we're trying to spare.
	 * @return int At most CATEGORY_PROBE_CAP + 1.
	 */
	private function probeCategorySize( Project $project, array $catTargetIds ): int {
		$limit = self::CATEGORY_PROBE_CAP + 1;
		$categorylinksTable = $this->getLinksTableName( $project, 'categorylinks' );
		// cl_sortkey (cl_target_id, cl_type, cl_sortkey, cl_from) covers this.
		$qb = $this->linksQueryBuilder( $project );
		$qb->select( 'COUNT(*)' )
			->from( "( SELECT 1 FROM $categorylinksTable WHERE cl_target_id IN (:catIds) LIMIT $limit )", 'probe' )
			->setParameter( 'catIds', $catTargetIds, ArrayParameterType::INTEGER );

		return (int)$this->executeQueryBuilder( $qb, $this->getLinksSlice( $project ) )->fetchOne();
	}

	/**
	 * A builder on the wiki section, where revision, page, comment and ip_changes live.
	 * @param Project $project
	 * @return QueryBuilder
	 */
	private function wikiQueryBuilder( Project $project ): QueryBuilder {
		return $this->getProjectsConnection( $project )->createQueryBuilder();
	}

	/**
	 * A builder on the section holding $project's links tables, which is the wiki's own section
	 * unless the wiki's links tables have been split out.
	 * @param Project $project
	 * @return QueryBuilder
	 */
	private function linksQueryBuilder( Project $project ): QueryBuilder {
		return $this->getProjectsConnection( $this->getLinksSlice( $project ) )->createQueryBuilder();
	}

	/**
	 * Restrict a revision query to the user: an actor ID for a named account, or a join to
	 * ip_changes on the hex bounds of the range for an IP range.
	 * @param QueryBuilder $qb
	 * @param Project $project
	 * @param User $user
	 */
	private function applyUserFilter( QueryBuilder $qb, Project $project, User $user ): void {
		if ( $user->isIpRange() ) {
			$ipcTable = $project->getTableName( 'ip_changes' );
			[ $hexStart, $hexEnd ] = IPUtils::parseRange( $user->getUsername() );
			$qb->join( 'revs', $ipcTable, 'ipc', 'ipc_rev_id = revs.rev_id' )
				->andWhere( 'ipc_hex BETWEEN :hexStart AND :hexEnd' )
				->setParameter( 'hexStart', $hexStart )
				->setParameter( 'hexEnd', $hexEnd );
		} else {
			$qb->andWhere( 'revs.rev_actor = :actorId' )
				->setParameter( 'actorId', $user->getActorId( $project ) );
		}
	}

	/**
	 * Stop before an unbounded number of links rows is held in memory. A prolific user against
	 * several large categories can match millions of (page, category) pairs, and chunking bounds
	 * each statement without bounding the total we accumulate.
	 * @param int $rowCount Rows read so far.
	 * @throws HttpException if the ceiling is passed.
	 */
	private function assertLinksLimit( int $rowCount ): void {
		if ( $rowCount > $this->getMaxLinks() ) {
			throw new HttpException( Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'error-category-links-too-many' );
		}
	}

	/**
	 * The APP_MAX_LINKS ceiling, or a permissive default where it isn't configured.
	 * @return int
	 */
	private function getMaxLinks(): int {
		return $this->parameterBag->has( 'app.max_links' )
			? (int)$this->parameterBag->get( 'app.max_links' )
			: 1000000;
	}

	/**
	 * Map the given category titles to their linktarget IDs, which is how categorylinks refers
	 * to them. Memoised per request, keyed by project and categories: the old static memo was
	 * keyed by neither and leaked across projects within a request.
	 * @param Project $project
	 * @param string[] $categories
	 * @return string[] lt_id => lt_title.
	 */
	private function getCategoryTargetIds( Project $project, array $categories ): array {
		$cacheKey = $project->getDatabaseName() . '|' . implode( '|', $categories );
		if ( isset( $this->categoryTargetIds[$cacheKey] ) ) {
			return $this->categoryTargetIds[$cacheKey];
		}
		// linktarget lives on the links section for wikis whose links tables were split out
		// (Commons, T398709), so it resolves its own database and runs on its own connection.
		$linktargetTable = $this->getLinksTableName( $project, 'linktarget' );
		$qb = $this->linksQueryBuilder( $project );
		$qb->select( 'lt_id', 'lt_title' )
			->from( $linktargetTable )
			->where( 'lt_title IN (:categories)' )
			->andWhere( 'lt_namespace = 14' )
			->setParameter( 'categories', $categories, ArrayParameterType::STRING );

		$this->categoryTargetIds[$cacheKey] = $this
			->executeQueryBuilder( $qb, $this->getLinksSlice( $project ) )
			->fetchAllKeyValue();
		return $this->categoryTargetIds[$cacheKey];
	}

	/**
	 * Get Edits given revision rows (JOINed on the page table).
	 * @param Project $project
	 * @param User $user
	 * @param array $revs Each must contain 'page_title' and 'namespace'.
	 * @return Edit[]
	 */
	public function getEditsFromRevs( Project $project, User $user, array $revs ): array {
		return Edit::getEditsFromRevs(
			$this->pageRepo,
			$this->editRepo,
			$this->userRepo,
			$project,
			$user,
			$revs
		);
	}
}
