<?php

namespace Telepedia\Extensions\CommunitySafety\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\TitleFactory;
use Wikimedia\Diff\Diff;
use Wikimedia\Diff\UnifiedDiffFormatter;

class SubmitEditJob extends Job {

	public const JOB_NAME = 'SubmitEditJob';

	// we cut revisions off at this length to avoid blowing through model limits
	private const MAX_DIFF_LENGTH = 50000;

	private Config $config;

	/**
	 * The ID of the revision so we can load it again from the database
	 *
	 * @var int
	 */
	private int $id;

	public function __construct(
		array $params,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly ConfigFactory $configFactory,
		private readonly RevisionStore $revisionStore,
		private readonly TitleFactory $titleFactory,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->config = $this->configFactory->makeConfig( 'CommunitySafety' );
		$this->id = $params['id'];
	}

	/**
	 * @inheritDoc
	 */
	public function run(): bool {
		$logger = LoggerFactory::getInstance( 'CommunitySafety' );

		$revision = $this->revisionStore->getRevisionById( $this->id );
		if ( !$revision ) {
			// revision doesn't exist, nothing we can do about it, something went wrong?!
			// just ack the job and move on
			$logger->warning( 'Revision {id} not found; abandoning job', [ 'id' => $this->id ] );

			return true;
		}

		$user = $revision->getUser();
		if ( !$user ) {
			// the username is hidden or missing; potentially has already been dealt with
			// in any case nothing we can do so also ack that and move on
			$logger->info( 'Revision {id} has no visible user; abandoning job', [ 'id' => $this->id ] );

			return true;
		}

		$newContent = $revision->getContent( SlotRecord::MAIN );
		if ( !$newContent ) {
			// the revision's text is hidden or missing
			$logger->info( 'Revision {id} has no visible content; abandoning job', [ 'id' => $this->id ] );

			return true;
		}

		$parentId = $revision->getParentId();
		$parent = $parentId ? $this->revisionStore->getRevisionById( $parentId ) : null;

		// if no parent it is a page creation so diff against nothing
		$oldText = $parent?->getContent( SlotRecord::MAIN )?->serialize() ?? '';
		$newText = $newContent->serialize();

		$diff = new Diff( explode( "\n", $oldText ), explode( "\n", $newText ) );
		$diffText = ( new UnifiedDiffFormatter() )->format( $diff );
		if ( trim( $diffText ) === '' ) {
			// nothing changed in the text, ie this edit did not touch the main slot so we don't
			// need to do anything
			return true;
		}
		$diffText = mb_substr( $diffText, 0, self::MAX_DIFF_LENGTH );

		$title = $this->titleFactory->newFromPageIdentity( $revision->getPage() );

		// if a page creation there is no previous revision, so we just link to the page itself
		$url = $title->getFullURL(
			$parentId ? [
				'diff' => $this->id,
				'oldid' => $parentId
			] : [ 'oldid' => $this->id ]
		);

		$payload = [
			'wiki' => $this->config->get( MainConfigNames::Sitename ),
			'wikiId' => $this->config->get( MainConfigNames::DBname ),
			'page' => $title->getPrefixedText(),
			'url' => $url,
			'editor' => $user->getName(),
			'summary' => $revision->getComment()?->text ?? '',
			'timestamp' => $revision->getTimestamp(),
			'diff' => $diffText,
		];

		$request = $this->httpRequestFactory->create(
			rtrim( $this->config->get( 'CommunitySafetyEndpoint' ), '/' ),
			[
				'method' => 'POST',
				'postData' => FormatJson::encode( $payload, false, FormatJson::ALL_OK ),
				'timeout' => 10,
			],
			__METHOD__
		);

		$request->setHeader( 'Content-Type', 'application/json' );
		$request->setHeader( 'Authorization', 'Bearer ' . $this->config->get( 'CommunitySafetyIngestKey' ) );
		$request->execute();

		$code = $request->getStatus();
		if ( $code === 202 ) {
			return true;
		}

		$context = [
			'id' => $this->id,
			'code' => $code,
			'body' => $request->getContent()
		];
		if ( $code === 400 || $code === 503 ) {

			$logger->error( 'CommunitySafety endpoint rejected revision {id} with {code}: {body}', $context );

			return true;
		}

		$logger->warning( 'CommunitySafety submission for revision {id} failed with {code}; rescheduling job', $context );

		return false;
	}
}
