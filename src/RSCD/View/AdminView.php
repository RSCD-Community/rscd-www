<?php

namespace RSCD\View;

use RSCD\View\Common\AdminPageView;

/**
 * Admin view — renders pages inside the admin section (/admin/).
 *
 * Thin subclass of AdminPageView that provides no additional behaviour.
 * Instantiated by admin controllers to wrap their page HTML in the admin
 * header, footer, and wrapper layout files.
 */
class AdminView extends AdminPageView {

}
