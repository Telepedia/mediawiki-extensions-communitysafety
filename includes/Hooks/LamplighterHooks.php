<?php

namespace Telepedia\Extensions\CommunitySafety\Hooks;

use JobQueueGroup;
use JobSpecification;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Telepedia\Extensions\CommunitySafety\Jobs\SubmitEditJob;

class LamplighterHooks implements PageSaveCompleteHook {

	public function __construct(
		private readonly JobQueueGroup $jobQueueGroup
	) {}

	/**
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		if ( $editResult->isNullEdit() ) {
			return;
		}
		
 		$this->jobQueueGroup->lazyPush(
			new JobSpecification(
				SubmitEditJob::JOB_NAME,
				[ 'id' => $revisionRecord->getId() ]
			)
		);
	}
}
