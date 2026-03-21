<?php

declare( strict_types = 1 );

namespace App\Tests\Controller;

/**
 * Integration tests for the Auto Edits tool.
 * @group integration
 * @covers \App\Controller\AutomatedEditsController
 */
class AutomatedEditsControllerTest extends ControllerTestAdapter {
	/**
	 * Test that the form can be retrieved.
	 */
	public function testIndex(): void {
		// Check basics.
		$this->client->request( 'GET', '/autoedits' );
		static::assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		// For now...
		if ( !static::getContainer()->getParameter( 'app.is_wmf' ) ||
			static::getContainer()->getParameter( 'app.single_wiki' )
		) {
			return;
		}

		// Should populate the appropriate fields.
		$crawler = $this->client->request( 'GET', '/autoedits/de.wikipedia.org?namespace=3&end=2017-01-01' );
		static::assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		static::assertEquals( 'de.wikipedia.org', $crawler->filter( '#project_input' )->attr( 'value' ) );
		static::assertEquals( 3, $crawler->filter( '#namespace_select option:selected' )->attr( 'value' ) );
		static::assertEquals( '2017-01-01', $crawler->filter( '[name=end]' )->attr( 'value' ) );

		// Legacy URL params.
		$crawler = $this->client->request( 'GET', '/autoedits?project=fr.wikipedia.org&namespace=5&begin=2017-02-01' );
		static::assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		static::assertEquals( 'fr.wikipedia.org', $crawler->filter( '#project_input' )->attr( 'value' ) );
		static::assertEquals( 5, $crawler->filter( '#namespace_select option:selected' )->attr( 'value' ) );
		static::assertEquals( '2017-02-01', $crawler->filter( '[name=start]' )->attr( 'value' ) );
	}

	/**
	 * Check that the result pages return successful responses.
	 */
	public function testResultPages(): void {
		if ( !static::getContainer()->getParameter( 'app.is_wmf' ) ) {
			return;
		}

		$this->assertSuccessfulRoutes( [
			'/autoedits/en.wikipedia/Example',
			'/autoedits/en.wikipedia/Example/1/2018-01-01/2018-02-01',
			'/nonautoedits-contributions/en.wikipedia/Example/1/2018-01-01/2018-02-01/2018-01-15T12:00:00',
			'/autoedits-contributions/en.wikipedia/Example/1/2018-01-01/2018-02-01/2018-01-15T12:00:00',
			// T420807
			'/autoedits-contributions/en.wikipedia.org/Example?tool=delsort',
			// T418067
			'/autoedits-contributions/en.wikipedia.org/Example?tool=MoveToDraft',
		] );
	}

	/**
	 * Check that the APIs return successful responses.
	 */
	public function testApis(): void {
		if ( !static::getContainer()->getParameter( 'app.is_wmf' ) ) {
			return;
		}

		// Non-automated edits endpoint is tested in self::testNonautomatedEdits().
		$this->assertSuccessfulRoutes( [
			'/api/project/automated_tools/en.wikipedia',
			'/api/user/automated_editcount/en.wikipedia/Example/1/2018-01-01/2018-02-01/2018-01-15T12:00:00',
			'/api/user/automated_edits/en.wikipedia/Example/1/2018-01-01/2018-02-01/2018-01-15T12:00:00',
		] );
	}

	/**
	 * Test automated tools endpoint.
	 */
	public function testAutomatedTools(): void {
		if ( !static::getContainer()->getParameter( 'app.is_wmf' ) ) {
			// Untestable :(
			return;
		}

		$url = '/api/project/automated_tools/en.wikipedia';
		$this->client->request( 'GET', $url );
		$response = $this->client->getResponse();
		static::assertEquals( 200, $response->getStatusCode() );
		static::assertEquals( 'application/json', $response->headers->get( 'content-type' ) );

		$data = json_decode( $response->getContent(), true );
		static::assertEquals( 'en.wikipedia.org', $data['project'] );
		static::assertArrayHasKey( 'Huggle', $data['tools'] );
		static::assertArrayHasKey( 'Twinkle', $data['tools'] );
	}

	/**
	 * Test automated edit counter endpoint.
	 */
	public function testAutomatedEditCount(): void {
		if ( !static::getContainer()->getParameter( 'app.is_wmf' ) ) {
			// Untestable :(
			return;
		}

		$url = '/api/user/automated_editcount/en.wikipedia/musikPuppet/all///1';
		$this->client->request( 'GET', $url );
		$response = $this->client->getResponse();
		static::assertEquals( 200, $response->getStatusCode() );
		static::assertEquals( 'application/json', $response->headers->get( 'content-type' ) );

		$data = json_decode( $response->getContent(), true );
		$toolNames = array_keys( $data['automated_tools'] );

		static::assertEquals( 'en.wikipedia.org', $data['project'] );
		static::assertEquals( 'musikPuppet', $data['username'] );
		static::assertGreaterThan( 15, $data['automated_editcount'] );
		static::assertGreaterThan( 35, $data['nonautomated_editcount'] );
		static::assertEquals(
			$data['automated_editcount'] + $data['nonautomated_editcount'],
			$data['total_editcount']
		);
		static::assertContains( 'Twinkle', $toolNames );
		static::assertContains( 'Huggle', $toolNames );
	}

	/**
	 * Test automated edits endpoint.
	 */
	public function testAutomatedEdits(): void {
		if ( !static::getContainer()->getParameter( 'app.is_wmf' ) ) {
			// Untestable :(
			return;
		}
		$url = '/api/user/automated_edits/en.wikipedia/MusikVarmint?tool=Huggle';
		$this->client->request( 'GET', $url );
		$response = $this->client->getResponse();
		static::assertEquals( 200, $response->getStatusCode() );
		static::assertEquals( 'application/json', $response->headers->get( 'content-type' ) );
		$data = json_decode( $response->getContent(), true );
		static::assertSame( 'Huggle', $data['tool'] );
		// User:MusikVarmint on enwiki should have at least 50 edits using Huggle,
		// and 50 is the per-page max for this endpoint.
		static::assertCount( 50, $data['automated_edits'] );
	}

	/**
	 * Test nonautomated edits endpoint.
	 */
	public function testNonautomatedEdits(): void {
		if ( !static::getContainer()->getParameter( 'app.is_wmf' ) ) {
			// untestable :(
			return;
		}

		// This test account *should* never edit again and be safe for testing...
		$url = '/api/user/nonautomated_edits/en.wikipedia/ThisIsaTest/all';
		$this->client->request( 'GET', $url );
		$response = $this->client->getResponse();
		static::assertEquals( 200, $response->getStatusCode() );
		static::assertEquals( 'application/json', $response->headers->get( 'content-type' ) );

		static::assertCount( 1, json_decode( $response->getContent(), true )['nonautomated_edits'] );
	}
}
