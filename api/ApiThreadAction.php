<?php

class ApiThreadAction extends ApiBase {
	
	public function getDescription() {
		return 'Allows actions to be taken on threads and posts in threaded discussions.';
	}
	
	public function getActions() {
		return array(
			'markread' => 'actionMarkRead',
			'markunread' => 'actionMarkUnread',
			'split' => 'actionSplit',
			'merge' => 'actionMerge',
//			'reply', // Not implemented
			'newthread' => 'actionNewThread',
//			'setsubject',
		);
	}
	
	protected function getParamDescription() {
		return array(
			'thread' => 'A list (pipe-separated) of thread IDs or titles to act on',
			'threadaction' => 'The action to take',
			'token' => 'An edit token (from ?action=query&prop=info&intoken=edit)',
			'talkpage' => 'The talkpage to act on (if applicable)',
			'subject' => 'The subject to set for the new or split thread',
			'reason' => 'If applicable, the reason/summary for the action',
			'newparent' => 'If merging a thread, the ID or title for its new parent',
			'text' => 'The text of the post to create',
		);
	}
	
	public function getExamples() {
		return array(
		
		);
	}
	
	public function getAllowedParams() {
		return array(
			'thread' => array(
					ApiBase::PARAM_ISMULTI => true,
				),
			'talkpage' => null,
			'threadaction' => array(
					ApiBase::PARAM_TYPE => array_keys( $this->getActions() ),
				),
			'token' => null,
			'subject' => null,
			'reason' => null,
			'newparent' => null,
			'text' => null,
		);
	}
	
	public function mustBePosted() { return true; }

	public function isWriteMode() {
		return true;
	}
	
	public function execute() {
		$params = $this->extractRequestParams();
		
		global $wgUser;
		
		if ( empty( $params['token'] ) ||
				!$wgUser->matchEditToken( $params['token'] ) ) {
			$this->dieUsage( 'sessionfailure' );
			return;
		}
		
		if ( empty( $params['threadaction'] ) ) {
			$this->dieUsage( 'missing-param', 'action' );
			return;
		}
		
		// Pull the threads from the parameters
		$threads = array();
		if ( !empty( $params['thread'] ) ) {
			foreach( $params['thread'] as $thread ) {
				$threadObj = null;
				if ( is_numeric( $thread ) ) {
					$threadObj = Threads::withId( $thread );
				} else {
					$title = Title::newFromText( $thread );
					$article = new Article( $title );
					$threadObj = Threads::withRoot( $article );
				}
				
				if ( $threadObj instanceof Thread ) {
					$threads[] = $threadObj;
				}
			}
		}
		
		// Find the appropriate module
		$action = $params['threadaction'];
		$actions = $this->getActions();
		
		$method = $actions[$action];
		
		call_user_func_array( array( $this, $method ), array( $threads, $params ) );
	}
	
	public function actionMarkRead( $threads, $params ) {
		global $wgUser;
		
		$result = array();
		
		foreach( $threads as $t ) {
			NewMessages::markThreadAsReadByUser( $t, $wgUser );
			$result[] =
				array(
					'result' => 'Success',
					'action' => 'markread',
					'id' => $t->id(),
					'title' => $t->title()->getPrefixedText()
				);
		}
		
		$this->getResult()->setIndexedTagName( $result, 'thread' );
		$this->getResult()->addValue( null, 'threadactions', $result );
	}
	
	public function actionMarkUnread( $threads, $params ) {
		global $wgUser;
		
		$result = array();
		
		foreach( $threads as $t ) {
			NewMessages::markThreadAsUnreadByUser( $t, $wgUser );
			
			$result[] =
				array(
					'result' => 'Success',
					'action' => 'markunread',
					'id' => $t->id(),
					'title' => $t->title()->getPrefixedText()
				);
		}
		
		
		$this->getResult()->setIndexedTagName( $result, 'thread' );
		$this->getResult()->addValue( null, 'threadaction', $result );
	}
	
	public function actionSplit( $threads, $params ) {
		global $wgUser;
		
		if ( count($threads) > 1 ) {
			$this->dieUsage( 'You may only split one thread at a time',
					'too-many-threads' );
			return;
		} elseif ( count($threads) < 1 ) {
			$this->dieUsage( 'You must specify a thread to split',
					'no-specified-threads' );
			return;
		}
		
		$thread = array_pop( $threads );
		
		if ( $thread->isTopmostThread() ) {
			$this->dieUsage( 'This thread is already a top-level thread.',
				'already-top-level' );
		}
		
		$title = null;
		$article = $thread->article();
		if ( empty($params['subject'] ) ||
			! Thread::validateSubject( $params['subject'], &$title, null, $article ) ) {
			
			$this->dieUsage( 'No subject, or an invalid subject, was specified',
				'no-valid-subject' );
		}
		
		$subject = $params['subject'];
		
		// Pull a reason, if applicable.
		$reason = '';
		if ( !empty($params['reason']) ) {
			$reason = $params['reason'];
		}
		
		// Do the split
		$thread->split( $subject, $reason );
		
		$result = array();
		$result[] =
			array(
				'result' => 'Success',
				'action' => 'split',
				'id' => $thread->id(),
				'title' => $thread->title()->getPrefixedText(),
				'newsubject' => $subject,
			);
		
		$this->getResult()->setIndexedTagName( $result, 'thread' );
		$this->getResult()->addValue( null, 'threadaction', $result );
	}
	
	public function actionMerge( $threads, $params ) {
		global $wgUser;
		
		if ( count( $threads ) < 1 ) {
			$this->dieUsage( 'You must specify a thread to merge',
				'no-specified-threads' );
			return;
		}
		
		if ( empty( $params['newparent'] ) ) {
			$this->dieUsage( 'You must specify a new parent thread to merge beneath',
				'no-parent-thread' );		
			return;
		}
		
		$newParent = $params['newparent'];
		if ( is_numeric( $newParent ) ) {
			$newParent = Threads::withId( $newParent );
		} else {
			$title = Title::newFromText( $newParent );
			$article = new Article( $title );
			$newParent = Threads::withRoot( $article );
		}
		
		if ( !$newParent ) {
			$this->dieUsage( 'The parent thread you specified was neither the title '.
					'of a thread, nor a thread ID.', 'invalid-parent-thread' );
			return;
		}
		
		// Pull a reason, if applicable.
		$reason = '';
		if ( !empty($params['reason']) ) {
			$reason = $params['reason'];
		}
		
		$result = array();
		
		foreach( $threads as $thread ) {
			$thread->moveToParent( $newParent, $reason );
			$result[] = 
				array(
					'result' => 'Success',
					'action' => 'merge',
					'id' => $thread->id(),
					'title' => $thread->title()->getPrefixedText(),
					'new-parent-id' => $newParent->id(),
					'new-parent-title' => $newParent->title()->getPrefixedText(),
					'new-ancestor-id' => $newParent->topmostThread()->id(),
					'new-ancestor-title' => $newParent->topmostThread()->title()->getPrefixedText(),
				);
		}
		
		$this->getResult()->setIndexedTagName( $result, 'thread' );
		$this->getResult()->addValue( null, 'threadaction', $result );
	}
	
	public function actionNewThread( $threads, $params ) {
		global $wgUser;
		
		if ( empty( $params['talkpage'] ) ) {
			$this->dieUsage( 'You must specify a talk-page to post the thread to',
				'missing-param' );
			
			return;
		}
		
		$talkpageTitle = Title::newFromText( $params['talkpage'] );
		
		if (!$talkpageTitle || !LqtDispatch::isLqtPage( $talkpageTitle ) ) {
			$this->dieUsage( 'The talkpage you specified is invalid, or does not '.
				'have discussion threading enabled.', 'invalid-talkpage' );
			return;
		}
		$talkpage = new Article( $talkpageTitle );
		
		if ( empty( $params['subject'] ) ) {
			$this->dieUsage( 'You must specify a thread subject',
				'missing-param' );
			return;
		}
		
		$subject = $params['subject'];
		$title = null;
		$subjectOk = Thread::validateSubject( $subject, &$title, null, $talkpage );
		
		if ( !$subjectOk ) {
			$this->dieUsage( 'The subject you specified is not valid',
				'invalid-subject' );
			
			return;
		}
		
		if ( empty( $params['text'] ) ) {
			$this->dieUsage( 'You must include text in your post', 'no-text' );
			return;
		}
		
		$summary = wfMsg( 'lqt-newpost-summary', $subject );
		
		if ( !empty( $params['summary'] ) ) {
			$summary = $params['summary'];
		}
		
		$text = $params['text'];
		
		$article = new Article( $title );
		
		// Inform hooks what we're doing
		LqtHooks::$editTalkpage = $talkpage;
		LqtHooks::$editArticle = $article;
		LqtHooks::$editThread = null;
		LqtHooks::$editType = 'new';
		LqtHooks::$editAppliesTo = null;
		
		$token = $params['token'];
		
		// All seems in order. Construct an API edit request
		$requestData = array(
			'action' => 'edit',
			'title' => $title->getPrefixedText(),
			'text' => $text,
			'summary' => $summary,
			'token' => $token,
			'basetimestamp' => wfTimestampNow(),
			'format' => 'json',
		);
		
		$editReq = new FauxRequest( $requestData, true );
		$internalApi = new ApiMain( $editReq, true );
		$internalApi->execute();
		
		$editResult = $internalApi->getResultData();
		
		if ( $editResult['edit']['result'] != 'Success' ) {
			$result = array( 'result' => 'EditFailure', 'details' => $editResult );
			$this->getResult()->addValue( null, $this->getModuleName(), $result );
			return;
		}
		
		$articleId = $editResult['edit']['pageid'];
		
		// Reload article data
		$article = Article::newFromId( $articleId );
		
		$thread = LqtView::postEditUpdates( 'new', null, $article, $talkpage,
					$subject, $summary, null, $text );
					
		$result = array(
			'result' => 'Success',
			'thread-id' => $thread->id(),
			'thread-title' => $thread->title()->getPrefixedText(),
		);
		
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}
	
	public function getVersion() {
		return __CLASS__ . ': $Id: $';
	}
}