<?php

class LqtHooks {
	static function onPageMove( $movepage, $ot, $nt ) {
		// Shortcut for non-LQT pages.
		if ( !self::isLqtPage( $ot ) )
			return true;
		
		// Move the threads on that page to the new page.
		$threads = Threads::where( array( Threads::articleClause( new Article( $ot ) ),
		                                  Threads::topLevelClause() ) );

		foreach ( $threads as $t ) {
			$t->moveToPage( $nt, false );
		}

		return true;
	}

	static function customizeOldChangesList( &$changeslist, &$s, $rc ) {
		if ( $rc->getTitle()->getNamespace() != NS_LQT_THREAD )
			return true;
	
		$thread = Threads::withRoot( new Article( $rc->getTitle() ) );
		if ( !$thread ) return true;

		LqtView::addJSandCSS();
		wfLoadExtensionMessages( 'LiquidThreads' );

		if ( $rc->mAttribs['rc_new'] ) {
			global $wgOut;
			
			$sig = "";
			$changeslist->insertUserRelatedLinks( $sig, $rc );

			// This should be stored in RC.
			$rev = Revision::newFromId( $rc->mAttribs['rc_this_oldid'] );
			$quote = $rev->getText();
			$link = '';
			if ( strlen( $quote ) > 230 ) {
				$sk = $changeslist->skin;
				$quote = substr( $quote, 0, 200 );
				$link = $sk->link( $thread->title(), wfMsg( 'lqt_rc_ellipsis' ),
						array( 'class' => 'lqt_rc_ellipsis' ), array(), array( 'known' ) );
			}
			
			$quote = $wgOut->parseInline( $quote ) . $link;

			if ( $thread->isTopmostThread() ) {
				$message_name = 'lqt_rc_new_discussion';
			} else {
				$message_name = 'lqt_rc_new_reply';
			}
			
			$tmp_title = $thread->article()->getTitle();
			$tmp_title->setFragment( '#' . LqtView::anchorName( $thread ) );
			
			// Make sure it points to the right page. The Pager seems to use the DB
			//  representation of a timestamp for its offset field, odd.
			$dbr = wfGetDB( DB_SLAVE );
			$offset = $dbr->timestamp( $thread->topmostThread()->modified() );

			$thread_link = $changeslist->skin->link( $tmp_title,
				htmlspecialchars($thread->subjectWithoutIncrement()),
				array(), array( 'offset' => $offset ), array( 'known' ) );

			$talkpage_link = $changeslist->skin->link(
				$thread->article()->getTitle(),
				null,
				array(), array(), array( 'known' ) );

			$s = wfMsg( $message_name, $thread_link, $talkpage_link, $sig )
				. Xml::tags( 'blockquote', array( 'class' => 'lqt_rc_blockquote' ), $quote );
				
			$classes = array();
			$changeslist->insertTags( $s, $rc, $classes );
			$changeslist->insertExtra( $s, $rc, $classes );
		} else {
			// Add whether it was original author.
			if ( $thread->author()->getName() != $rc->mAttribs['rc_user_text'] ) {
				$appendix = Xml::tags( 'span',
										array( 'class' => 'lqt_rc_author_notice ' .
														'lqt_rc_author_notice_others' ),
										wfMsgExt( 'lqt_rc_author_others', 'parseinline' )
									);
			
				$s .= ' ' . $appendix;
			}
		}
		return true;
	}

	static function setNewtalkHTML( $skintemplate, $tpl ) {
		global $wgUser, $wgTitle, $wgOut;
		wfLoadExtensionMessages( 'LiquidThreads' );
		$newmsg_t = SpecialPage::getTitleFor( 'NewMessages' );
		$watchlist_t = SpecialPage::getTitleFor( 'Watchlist' );
		$usertalk_t = $wgUser->getTalkPage();
		if ( $wgUser->getNewtalk()
				&& ! $newmsg_t->equals( $wgTitle )
				&& ! $watchlist_t->equals( $wgTitle )
				&& ! $usertalk_t->equals( $wgTitle )
				) {
			$s = wfMsgExt( 'lqt_youhavenewmessages', array( 'parseinline' ),
							$newmsg_t->getFullURL() );
			$tpl->set( "newtalk", $s );
			$wgOut->setSquidMaxage( 0 );
		} else {
			$tpl->set( "newtalk", '' );
		}

		return true;
	}
	
	static function beforeWatchlist( &$conds, &$tables, &$join_conds, &$fields ) {
		global $wgOut, $wgUser;
		
		$db = wfGetDB( DB_SLAVE );
	
		if ( !in_array( 'page', $tables ) ) {
			$tables[] = 'page';
			// Yes, this is the correct field to join to. Weird naming.
			$join_conds['page'] = array( 'LEFT JOIN', 'rc_cur_id=page_id' );
		}
		$conds[] = "page_namespace != " . $db->addQuotes(NS_LQT_THREAD);
	
		$talkpage_messages = NewMessages::newUserMessages( $wgUser );
		$tn = count( $talkpage_messages );
	
		$watch_messages = NewMessages::watchedThreadsForUser( $wgUser );
		$wn = count( $watch_messages );
	
		if ( $tn == 0 && $wn == 0 )
			return true;
	
		LqtView::addJSandCSS();
		wfLoadExtensionMessages( 'LiquidThreads' );
		$messages_title = SpecialPage::getTitleFor( 'NewMessages' );
		$new_messages = wfMsgExt( 'lqt-new-messages', 'parseinline' );
		
		$sk = $wgUser->getSkin();
		$link = $sk->link( $messages_title, $new_messages,
								array( 'class' => 'lqt_watchlist_messages_notice' ) );
		$wgOut->addHTML( $link );
	
		return true;
	}
	
	static function getPreferences( $user, &$preferences ) {
		global $wgEnableEmail;
		
		if ($wgEnableEmail) {
			wfLoadExtensionMessages( 'LiquidThreads' );
			$preferences['lqtnotifytalk'] =
				array(
					'type' => 'toggle',
					'label-message' => 'lqt-preference-notify-talk',
					'section' => 'personal/email'
				);
		}
		
		return true;
	}
	
	static function updateNewtalkOnEdit( $article ) {
		$title = $article->getTitle();
		
		if ( LqtDispatch::isLqtPage( $title ) ) {
			// They're only editing the header, don't update newtalk.
			return false;
		}
		
		return true;
	}
	
	static function dumpThreadData( $writer, &$out, $row, $title ) {
		$editedStati = array( Threads::EDITED_NEVER => 'never',
								Threads::EDITED_HAS_REPLY => 'has-reply',
								Threads::EDITED_BY_AUTHOR => 'by-author',
								Threads::EDITED_BY_OTHERS => 'by-others' );
		$threadTypes = array( Threads::TYPE_NORMAL => 'normal',
								Threads::TYPE_MOVED => 'moved',
								Threads::TYPE_DELETED => 'deleted' );
		// Is it a thread
		if ( $row->thread_id ) {
			$thread = new Thread( $row );
			$threadInfo = "\n";
			$attribs = array();
			$attribs['ThreadSubject'] = $thread->subject();
			if ($thread->hasSuperThread()) {
				$attribs['ThreadParent'] = $thread->superThread()->id();
			}
			$attribs['ThreadAncestor'] = $thread->topmostThread()->id();
			$attribs['ThreadPage'] = $thread->article()->getTitle()->getPrefixedText();
			$attribs['ThreadID'] = $thread->id();
			if ( $thread->hasSummary() && $thread->summary() ) {
				$attribs['ThreadSummaryPage'] = $thread->summary()->getId();
			}
			$attribs['ThreadAuthor'] = $thread->author()->getName();
			$attribs['ThreadEditStatus'] = $editedStati[$thread->editedness()];
			$attribs['ThreadType'] = $threadTypes[$thread->type()];
			
			foreach( $attribs as $key => $value ) {
				$threadInfo .= "\t".Xml::element( $key, null, $value ) . "\n";
			}
			
			$out .= Xml::tags( 'DiscussionThreading', null, $threadInfo ) . "\n";
		}
		
		return true;
	}
	
	static function modifyExportQuery( $db, &$tables, &$cond, &$opts, &$join ) {
		$tables[] = 'thread';
		
		$join['thread'] = array( 'left join', array( 'thread_root=page_id' ) );
		
		return true;
	}
}