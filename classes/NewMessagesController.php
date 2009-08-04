<?php
if ( !defined( 'MEDIAWIKI' ) ) die;

class NewMessages {

	static function markThreadAsUnreadByUser( $thread, $user ) {
		self::writeUserMessageState( $thread, $user, null );
	}

	static function markThreadAsReadByUser( $thread, $user ) {
		self::writeUserMessageState( $thread, $user, wfTimestampNow() );
	}

	private static function writeUserMessageState( $thread, $user, $timestamp ) {
		if ( is_object( $thread ) ) {
			$thread_id = $thread->id();
		} else if ( is_integer( $thread ) ) {
			$thread_id = $thread;
		} else {
			throw new MWException( "writeUserMessageState expected Thread or integer but got $thread" );
		}

		if ( is_object( $user ) ) {
			$user_id = $user->getID();
		} else if ( is_integer( $user ) ) {
			$user_id = $user;
		} else {
			throw new MWException( "writeUserMessageState expected User or integer but got $user" );
		}

		$dbw = wfGetDB( DB_MASTER );
		
		$dbw->replace( 'user_message_state', array( array( 'ums_user', 'ums_thread' ) ),
						array( 'ums_user' => $user_id, 'ums_thread' => $thread_id,
								'ums_read_timestamp' => $timestamp ), __METHOD__ );
	}

	/**
	 * Write a user_message_state for each user who is watching the thread.
	 * If the thread is on a user's talkpage, set that user's newtalk.
	*/
	static function writeMessageStateForUpdatedThread( $t, $type, $changeUser ) {
		global $wgUser;
		
		wfDebugLog( 'LiquidThreads', 'Doing notifications' );

		$dbw =& wfGetDB( DB_MASTER );

		$tpTitle = $t->article()->getTitle();
		$root_t = $t->root()->getTitle();

		// Select any applicable watchlist entries for the thread.
		$talkpageWhere = array( 'wl_namespace' => $tpTitle->getNamespace(),
								'wl_title' => $tpTitle->getDBkey() );
		$rootWhere = array( 'wl_namespace' => $root_t->getNamespace(),
								'wl_title' => $root_t->getDBkey() );
								
		$talkpageWhere = $dbw->makeList( $talkpageWhere, LIST_AND );
		$rootWhere = $dbw->makeList( $rootWhere, LIST_AND );
		
		$where_clause = $dbw->makeList( array( $talkpageWhere, $rootWhere ), LIST_OR );
		
		// <= 1.15 compatibility, it kinda sucks having to do all this up here.
		$tables = array( 'watchlist', 'user_message_state' );
		$joins = array( 'user_message_state' =>
							array( 'left join',
								array( 'ums_user=wl_user', 'ums_thread' => $t->id() ) ) );
		$fields = array( 'wl_user', 'ums_user', 'ums_read_timestamp' );
		
		$oldPrefCompat = false;
		global $wgVersion;
		if ( version_compare( $wgVersion, '1.16', '<' ) ) {
			$oldPrefCompat = true;
			
			$tables[] = 'user';
			$joins['user'] = array( 'left join', 'user_id=wl_user' );
			$fields[] = 'user_options';
		} else {
			$tables[] = 'user_properties';
			$joins['user_properties'] = 
				array(
						'left join',
						array( 'up_user=wl_user',
							'up_property' => 'lqtnotifytalk',
						)
					);
			$fields[] = 'up_value';
		}

		// Pull users to update the message state for, including whether or not a
		//  user_message_state row exists for them, and whether or not to send an email
		//  notification.
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( $tables, $fields, $where_clause, __METHOD__, array(), $joins);
		
		$insert_rows = array();
		$update_tuples = array();
		$notify_users = array();
		while( $row = $dbr->fetchObject( $res ) ) {
			// Don't notify yourself
			if ( $changeUser->getId() == $row->wl_user )
				continue;
				
			if ( $row->ums_user && !$row->ums_read_timestamp ) {
				// It's already positive.
			} else {
				$insert_rows[] =
					array(
						'ums_user' => $row->wl_user,
						'ums_thread' => $t->id(),
						'ums_read_timestamp' => null,
					);
					
				// Set newtalk
				$u = User::newFromId( $row->wl_user );
				$u->setNewtalk( true );
			}
			
			$wantsTalkNotification = false;
			
			if ( $oldPrefCompat ) {
				$decodedOptions = self::decodeUserOptions( $row->user_options );
				
				$wantsTalkNotification = ( is_null( $decodedOptions['lqtnotifytalk'] ) && 
						User::getDefaultOption( 'lqtnotifytalk' ) ) || $row->up_value;
			} else {
				$wantsTalkNotification =
					(is_null($row->up_value) && User::getDefaultOption( 'lqtnotifytalk' ) )
						|| $row->up_value;
			}
			
			if ( $wantsTalkNotification  ) {
				$notify_users[] = $row->wl_user;
			}
		}
		
		// Add user talk notification
		if ( $t->article()->getTitle()->getNamespace() == NS_USER_TALK ) {
			$name = $t->article()->getTitle()->getText();
			
			$user = User::newFromName( $name );
			if ( $user ) {
				$user->setNewtalk( true );
				
				$insert_rows[] = array( 'ums_user' => $user->getId(),
										'ums_thread' => $t->id(),
										'ums_read_timestamp' => null );
				
				if ( $user->getOption( 'enotifusertalkpages' ) ) {
					$notify_users[] = $user->getId();
				}
			}
		}
		
		// Do the actual updates
		if ( count($insert_rows) ) {
			$dbw->replace( 'user_message_state', array( array( 'ums_user', 'ums_thread' ) ),
							$insert_rows, __METHOD__ );
		}
		
		if ( count($notify_users) ) {
			self::notifyUsersByMail( $t, $notify_users, wfTimestampNow(), $type );
		}
	}
	
	// Would refactor User::decodeOptions, but the whole point is that this is
	//  compatible with old code :)
	static function decodeUserOptions( $str ) {
		$opts = array();
		$a = explode( "\n", $str );
		foreach ( $a as $s ) {
			$m = array();
			if ( preg_match( "/^(.[^=]*)=(.*)$/", $s, $m ) ) {
				$opts[$m[1]] = $m[2];
			}
		}
		
		return $opts;
	}
	
	static function notifyUsersByMail( $t, $watching_users, $timestamp, $type ) {
		wfLoadExtensionMessages( 'LiquidThreads' );
		$messages = array(
			Threads::CHANGE_REPLY_CREATED => 'lqt-enotif-reply',
			Threads::CHANGE_NEW_THREAD => 'lqt-enotif-newthread',
		);
		$subjects = array(
			Threads::CHANGE_REPLY_CREATED => 'lqt-enotif-subject-reply',
			Threads::CHANGE_NEW_THREAD => 'lqt-enotif-subject-newthread',
		);
			
		if ( !isset($messages[$type]) || !isset($subjects[$type]) ) {
			wfDebugLog( 'LiquidThreads', "Email notification failed: type $type unrecognised" );
			return;
		} else {
			$msgName = $messages[$type];
			$subjectMsg = $subjects[$type];
		}
		
		// Send email notification, fetching all the data in one go
		
		global $wgVersion;
		$tables = array( 'user' );
		$join_conds = array();
		$oldPreferenceFormat = false;
		if (version_compare( $wgVersion, '1.16', '<' )) {
			$oldPreferenceFormat = true;
		} else {
			$tables[] = 'user_properties';
			
			$join_conds['user_properties'] =
				array( 'left join', 
						array(
							'up_user=user_id',
							'up_property' => 'timecorrection'
						)
					);
		}
		
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( array( 'user' ), '*',
							array( 'user_id' => $watching_users ), __METHOD__, array(),
							$join_conds
						);
		
		while( $row = $dbr->fetchObject( $res ) ) {
			$u = User::newFromRow( $row );
			
			global $wgLang;
			
			$permalink = LqtView::permalinkUrl( $t );
			
			// Adjust with time correction
			if ($oldPreferenceFormat) {
				$u = User::newFromId( $row->user_id );
				$timeCorrection = $u->getOption( 'timecorrection' );
			} else {
				$timeCorrection = $row->up_value;
			}
			$adjustedTimestamp = $wgLang->userAdjust( $timestamp, $timeCorrection );
			
			$date = $wgLang->date( $adjustedTimestamp );
			$time = $wgLang->time( $adjustedTimestamp );
			
			$talkPage = $t->article()->getTitle()->getPrefixedText();
			$msg = wfMsg( $msgName, $u->getName(), $t->subjectWithoutIncrement(),
							$date, $time, $talkPage, $permalink );
							
			global $wgPasswordSender;
							
			$from = new MailAddress( $wgPasswordSender, 'WikiAdmin' );
			$to   = new MailAddress( $u );
			$subject = wfMsg( $subjectMsg, $t->subjectWithoutIncrement() );
			
			UserMailer::send( $to, $from, $subject, $msg );
		}
	}

	static function newUserMessages( $user ) {
		$talkPage = new Article( $user->getUserPage()->getTalkPage() );
		
		$dbr = wfGetDB( DB_SLAVE );
		
		$joinConds = array( 'ums_user' => null );
		$joinConds[] = $dbr->makeList( array( 'ums_user' => $user->getId(),
												'ums_thread=thread_id' ), LIST_AND );
		$joinClause = $dbr->makeList( $joinConds, LIST_OR );
		
		$res = $dbr->select( array( 'thread', 'user_message_state' ), '*',
							array( 'ums_read_timestamp' => null,
									Threads::articleClause( $talkPage ) ),
							__METHOD__, array(),
							array(
								'user_message_state' =>
									array( 'LEFT OUTER JOIN', $joinClause )
							) );
							
		return Threads::loadFromResult( $res, $dbr );
	}

	static function watchedThreadsForUser( $user ) {
		$talkPage = new Article( $user->getUserPage()->getTalkPage() );
		
		$dbr = wfGetDB( DB_SLAVE );
		
		$res = $dbr->select( array( 'thread', 'user_message_state' ), '*',
								array( 'ums_read_timestamp' => null,
										'ums_user' => $user->getId(),
										'not (' . Threads::articleClause( $talkPage ) . ')',
									),
								__METHOD__, array(),
								array( 'user_message_state' =>
									array( 'INNER JOIN', 'ums_thread=thread_id' ),
								) );
		
		return Threads::loadFromResult( $res, $dbr );
	}
}