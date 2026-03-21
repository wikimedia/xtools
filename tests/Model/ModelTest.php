<?php

declare( strict_types = 1 );

namespace App\Tests\Model;

use App\Model\Edit;
use App\Model\GlobalContribs;
use App\Model\Page;
use App\Model\Project;
use App\Model\SimpleEditCounter;
use App\Model\User;
use App\Repository\EditRepository;
use App\Repository\GlobalContribsRepository;
use App\Repository\PageRepository;
use App\Repository\SimpleEditCounterRepository;
use App\Repository\UserRepository;
use App\Tests\TestAdapter;

/**
 * @covers \App\Model\Model
 */
class ModelTest extends TestAdapter {
	public function testBasics(): void {
		// Use SimpleEditCounter since Model is abstract.
		$repo = $this->createMock( SimpleEditCounterRepository::class );
		$project = $this->createMock( Project::class );
		$user = $this->createMock( User::class );
		$start = '2020-01-01';
		$end = '2020-02-01';

		$model = new SimpleEditCounter(
			$repo,
			$project,
			$user,
			'all',
			strtotime( $start ),
			strtotime( $end )
		);

		static::assertEquals( $model->getRepository(), $repo );
		static::assertEquals( $model->getProject(), $project );
		static::assertEquals( $model->getUser(), $user );
		static::assertNull( $model->getPage() );
		static::assertEquals( 'all', $model->getNamespace() );
		static::assertEquals( strtotime( $start ), $model->getStart() );
		static::assertEquals( $start, $model->getStartDate() );
		static::assertEquals( strtotime( $end ), $model->getEnd() );
		static::assertEquals( $end, $model->getEndDate() );
		static::assertTrue( $model->hasDateRange() );
		static::assertNull( $model->getLimit() );
		static::assertFalse( $model->getOffset() );
		static::assertNull( $model->getOffsetISO() );
	}

	public function testGetOffsetISO(): void {
		$repo = $this->createMock( GlobalContribsRepository::class );
		$pageRepo = $this->createMock( PageRepository::class );
		$userRepo = $this->createMock( UserRepository::class );
		$editRepo = $this->createMock( EditRepository::class );
		$user = $this->createMock( User::class );
		$page = $this->createMock( Page::class );
		$start = '2020-01-01';
		$end = '2020-02-01';

		$model = new GlobalContribs(
			$repo,
			$pageRepo,
			$userRepo,
			$editRepo,
			$user,
			0,
			strtotime( $start ),
			strtotime( $end ),
		);

		$edits = [ new Edit( $editRepo, $userRepo, $page, [
			'rev_id' => 123,
			'username' => 'Example',
			'minor' => false,
			'timestamp' => '20200115120000',
		] ) ];

		static::assertNull( $model->getOffsetISO() );
		static::assertSame( '2020-01-15T12:00:00Z', $model->getOffsetISO( $edits ) );
	}
}
