<?php

class ThreadHistoryListingView extends ThreadPermalinkView {
	function show() {
		if ( ! $this->thread ) {
			$this->showMissingThreadPage();
			return false;
		}

		$this->thread->updateHistory();

		$this->output->setPageTitle( wfMessage( 'lqt-history-title' ) );
		$this->output->setSubtitle(
			$this->getSubtitle() . '<br />' .
				wfMessage( 'lqt_hist_listing_subtitle' )->escaped()
		);
		$this->showThreadHeading( $this->thread );

		$pager = new ThreadHistoryPager( $this, $this->thread );

		$html = $pager->getNavigationBar() .
				$pager->getBody() .
				$pager->getNavigationBar();

		$this->output->addHTML( $html );

		$this->showThread( $this->thread );

		return false;
	}

	function customizeTabs( $skin, &$links ) {
		parent::customizeTabs( $skin, $links );
		$links['history']['class'] = 'selected';
	}

	function customizeNavigation( $skin, &$links ) {
		parent::customizeNavigation( $skin, $links );
		$links['views']['history']['class'] = 'selected';
		$links['views']['view']['class'] = '';
	}
}
