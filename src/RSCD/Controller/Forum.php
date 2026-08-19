<?php

namespace RSCD\Controller;

use Illuminate\Database\Capsule\Manager as Capsule;
use RSCD\Util\BBCode;
use RSCD\Util\Dates;
use RSCD\Util\Strings;
use RSCD\View\ShopView;

/**
 * The community forums — minimal boards/topics/posts with BBCode.
 *
 * Same login as the rest of the site. Guests read everything; posting
 * requires signing in (authorize(true) redirects to the sign-in page).
 * "Staff" means a user whose access policies grant the admin console
 * (_AdminConsole_View), the same test the page header uses for the
 * Admin console link.
 *
 * Pages:
 *   GET  /forums/                       board list (+ board management, staff)
 *   GET  /forums/board/id%3DB/page%3DN/ topic list
 *   GET  /forums/topic/id%3DT/page%3DN/ posts + inline reply form
 *   GET  /forums/new-topic/id%3DB/      new-topic form
 * Actions (POST, PRG with ?msg=/?err= flashes):
 *   new-topic, reply, edit-post (GET form + POST), delete-post,
 *   delete-topic, lock-topic, sticky-topic (staff),
 *   create-board, edit-board, delete-board (staff)
 *
 * Rules: authors may edit their own posts (edits are stamped) and delete
 * their own posts except a topic's opening post; a topic may be deleted by
 * its author only while nobody else has replied, by staff always. Locked
 * topics take no replies or edits from non-staff. A locked board only lets
 * staff start topics (replies inside its topics stay open — that is what
 * topic locks are for).
 */
class Forum extends \RSCD\Controller\ObjectController {

    const TOPICS_PER_PAGE = 25;
    const POSTS_PER_PAGE = 15;
    const TITLE_MAX = 120;
    const BODY_MAX = 20000;

    /**
     * Initialise with the public-facing view.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Default action — the board list.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $state->request->action = 'forums';
        $state->request->method = 'GET';
        return $this->httpGetForums($state);
    }

    /**
     * Resolve the active user for a read-only page (guests welcome).
     *
     * @return object Application state.
     */
    protected function resolveUser() {
        $this->authorize();
        return $this->getState();
    }

    /**
     * Require a signed-in user for a write action.
     *
     * @return object Application state with activeUser set.
     */
    protected function requireUser() {
        $this->authorize(true);
        return $this->getState();
    }

    /**
     * Whether the active user has staff (admin console) access.
     *
     * @return bool
     */
    protected function isStaff() {
        return in_array('_AdminConsole_View', RuleManager::getAllowedConditions());
    }

    /**
     * Render ?msg= / ?err= flash parameters plus an optional response object.
     *
     * @param  object      $state    Application state.
     * @param  object|null $response Optional response with errors/messages.
     * @return string                Alert markup, or an empty string.
     */
    protected function buildAlertsHtml($state, $response = null) {
        $alerts = '';
        $err = $state->url->getVariable('err');
        $msg = $state->url->getVariable('msg');
        $errors   = array_merge(!empty($err) ? [rawurldecode($err)] : [], !empty($response->errors) ? $response->errors : []);
        $messages = array_merge(!empty($msg) ? [rawurldecode($msg)] : [], !empty($response->messages) ? $response->messages : []);
        if(!empty($errors)) {
            $alerts .= '<div class="alert alert-danger" role="alert">' . Strings::displayText(implode(', ', $errors)) . '</div>';
        }
        if(!empty($messages)) {
            $alerts .= '<div class="alert alert-success" role="alert">' . Strings::displayText(implode(', ', $messages)) . '</div>';
        }
        return $alerts;
    }

    /**
     * Redirect back to a forum page with a flash message.
     *
     * @param object $state Application state.
     * @param string $path  Path under the base URL.
     * @param string $key   'msg' or 'err'.
     * @param string $text  Flash message text.
     */
    protected function redirectWithFlash($state, $path, $key, $text) {
        return $state->app->redirect($state->url->getBaseUrl() . $path . '?' . http_build_query([$key => $text]));
    }

    /**
     * Format a unix timestamp for display.
     *
     * @param  int    $timestamp Unix timestamp.
     * @return string
     */
    protected function formatDate($timestamp) {
        return Dates::display($timestamp);
    }

    /**
     * Simple Previous/Next pagination links.
     *
     * @param  object $state Application state.
     * @param  string $path  Page path without the page variable (e.g. 'forums/board/id%3D2/').
     * @param  int    $page  Current 1-based page.
     * @param  int    $pages Total pages.
     * @return string        HTML, empty when there is a single page.
     */
    protected function buildPagerHtml($state, $path, $page, $pages) {
        if($pages <= 1) {
            return '';
        }
        $base = $state->url->getBaseUrl() . $path;
        $html = '<p class="forum-pager">Page ' . $page . ' of ' . $pages;
        if($page > 1) {
            $html .= ' &middot; <a href="' . $base . 'page%3D' . ($page - 1) . '/">&laquo; Previous</a>';
        }
        if($page < $pages) {
            $html .= ' &middot; <a href="' . $base . 'page%3D' . ($page + 1) . '/">Next &raquo;</a>';
        }
        return $html . '</p>';
    }

    /**
     * Board list page.
     *
     * @param object $state Application state.
     */
    protected function httpGetForums($state) {
        $state = $this->resolveUser();
        $boards = Capsule::table('forum_board')->orderBy('sort')->orderBy('id')->get();
        $base = $state->url->getBaseUrl();

        $rows = '';
        foreach($boards as $board) {
            $last = Capsule::table('forum_topic')->where('board_id', $board->id)->orderBy('last_post_at', 'DESC')->first();
            $rows .= '<tr>'
                . '<td><a href="' . $base . 'forums/board/id%3D' . (int)$board->id . '/"><b>' . Strings::displayText($board->name) . '</b></a>'
                . ($board->locked ? ' <i>(staff topics only)</i>' : '')
                . '<br /><span class="forum-desc">' . Strings::displayText($board->description) . '</span></td>'
                . '<td>' . (int)$board->topics . '</td>'
                . '<td>' . (int)$board->posts . '</td>'
                . '<td>' . (!empty($last->id)
                    ? '<a href="' . $base . 'forums/topic/id%3D' . (int)$last->id . '/">' . Strings::displayText($last->title) . '</a><br />' . $this->formatDate($last->last_post_at)
                    : '-') . '</td>'
                . '</tr>';
        }
        $listing = count($boards) > 0
            ? '<table class="data-table forum-table"><tr><th>Board</th><th>Topics</th><th>Posts</th><th>Latest topic</th></tr>' . $rows . '</table>'
            : '<p>No boards have been created yet.</p>';

        $page = $state->view->getViewLayout('forum' . DIRECTORY_SEPARATOR . 'index.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', $this->buildAlertsHtml($state));
        $page->injectHtml('listing', $listing);
        $page->injectHtml('admin', $this->isStaff() ? $this->buildBoardAdminHtml($state, $boards) : '');
        $state->view->setPage($page->get('html'), [], 'Forums');
    }

    /**
     * Board management forms shown to staff under the board list.
     *
     * @param  object $state  Application state.
     * @param  mixed  $boards Board rows.
     * @return string         HTML.
     */
    protected function buildBoardAdminHtml($state, $boards) {
        $base = $state->url->getBaseUrl();
        $html = '<div class="account-panel forum-admin"><h3>Board management</h3>';
        foreach($boards as $board) {
            $html .= '<form class="forum-admin-row" action="' . $base . 'forums/edit-board/" method="POST">'
                . '<input type="hidden" name="id" value="' . (int)$board->id . '" />'
                . '<input class="form-control" type="text" name="name" value="' . Strings::displayText($board->name) . '" maxlength="80" required />'
                . '<input class="form-control" type="text" name="description" value="' . Strings::displayText($board->description) . '" maxlength="255" />'
                . '<input class="form-control forum-admin-sort" type="number" name="sort" value="' . (int)$board->sort . '" />'
                . '<label class="checkbox-label"><input type="checkbox" name="locked" value="1"' . ($board->locked ? ' checked' : '') . ' />Staff topics only</label>'
                . '<button class="btn" type="submit">Save</button>'
                . '</form>'
                . '<form class="forum-admin-row" action="' . $base . 'forums/delete-board/" method="POST">'
                . '<input type="hidden" name="id" value="' . (int)$board->id . '" />'
                . '<label class="checkbox-label"><input type="checkbox" name="confirm" value="1" required />I\'m sure, delete this board and all its topics</label>'
                . '<button class="btn btn-danger" type="submit">Delete board and all its topics</button>'
                . '</form>';
        }
        $html .= '<h3>Create a board</h3>'
            . '<form class="forum-admin-row" action="' . $base . 'forums/create-board/" method="POST">'
            . '<input class="form-control" type="text" name="name" placeholder="Name" maxlength="80" required />'
            . '<input class="form-control" type="text" name="description" placeholder="Description" maxlength="255" />'
            . '<input class="form-control forum-admin-sort" type="number" name="sort" value="0" />'
            . '<label class="checkbox-label"><input type="checkbox" name="locked" value="1" />Staff topics only</label>'
            . '<button class="btn btn-primary" type="submit">Create</button>'
            . '</form></div>';
        return $html;
    }

    /**
     * Topic list for one board.
     *
     * @param object $state Application state.
     */
    protected function httpGetBoard($state) {
        $state = $this->resolveUser();
        $board = Capsule::table('forum_board')->where('id', (int)$state->url->getVariable('id'))->first();
        if(empty($board->id)) {
            return $this->redirectWithFlash($state, 'forums/', 'err', 'That board does not exist.');
        }
        $base = $state->url->getBaseUrl();
        $pageNo = max(1, (int)$state->url->getVariable('page'));
        $total = Capsule::table('forum_topic')->where('board_id', $board->id)->count();
        $pages = max(1, (int)ceil($total / static::TOPICS_PER_PAGE));
        $pageNo = min($pageNo, $pages);

        $topics = Capsule::table('forum_topic')
            ->leftJoin('user', 'user.id', '=', 'forum_topic.user_id')
            ->where('board_id', $board->id)
            ->orderBy('sticky', 'DESC')->orderBy('last_post_at', 'DESC')
            ->offset(($pageNo - 1) * static::TOPICS_PER_PAGE)->limit(static::TOPICS_PER_PAGE)
            ->get(['forum_topic.*', 'user.name AS author']);

        $rows = '';
        foreach($topics as $topic) {
            $flags = ($topic->sticky ? '<b>Sticky:</b> ' : '') . ($topic->locked ? '<i>[locked]</i> ' : '');
            $rows .= '<tr>'
                . '<td>' . $flags . '<a href="' . $base . 'forums/topic/id%3D' . (int)$topic->id . '/">' . Strings::displayText($topic->title) . '</a></td>'
                . '<td>' . Strings::displayText((string)($topic->author ?? 'Unknown')) . '</td>'
                . '<td>' . max(0, (int)$topic->posts - 1) . '</td>'
                . '<td>' . $this->formatDate($topic->last_post_at) . '</td>'
                . '</tr>';
        }
        $listing = $total > 0
            ? '<table class="data-table forum-table"><tr><th>Topic</th><th>Author</th><th>Replies</th><th>Last post</th></tr>' . $rows . '</table>'
            : '<p>No topics yet.</p>';

        $canPost = !empty($state->activeUser->id) && (!$board->locked || $this->isStaff());
        $newTopic = $canPost
            ? '<a class="btn btn-primary" href="' . $base . 'forums/new-topic/id%3D' . (int)$board->id . '/">New topic</a>'
            : (empty($state->activeUser->id)
                ? '<a href="' . $base . 'sign-in/">Sign in</a> to post.'
                : 'Only staff can start topics on this board.');

        $page = $state->view->getViewLayout('forum' . DIRECTORY_SEPARATOR . 'board.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', $this->buildAlertsHtml($state));
        $page->injectHtml('board.name', Strings::displayText($board->name));
        $page->injectHtml('board.description', Strings::displayText($board->description));
        $page->injectHtml('listing', $listing);
        $page->injectHtml('pager', $this->buildPagerHtml($state, 'forums/board/id%3D' . (int)$board->id . '/', $pageNo, $pages));
        $page->injectHtml('new_topic', $newTopic);
        $state->view->setPage($page->get('html'), [], $board->name);
    }

    /**
     * Posts in one topic, with the inline reply form and moderation controls.
     *
     * @param object $state Application state.
     */
    protected function httpGetTopic($state) {
        $state = $this->resolveUser();
        $topic = Capsule::table('forum_topic')->where('id', (int)$state->url->getVariable('id'))->first();
        if(empty($topic->id)) {
            return $this->redirectWithFlash($state, 'forums/', 'err', 'That topic does not exist.');
        }
        $board = Capsule::table('forum_board')->where('id', $topic->board_id)->first();
        $base = $state->url->getBaseUrl();
        $staff = $this->isStaff();
        $userId = (int)($state->activeUser->id ?? 0);

        $pageNo = max(1, (int)$state->url->getVariable('page'));
        $total = Capsule::table('forum_post')->where('topic_id', $topic->id)->count();
        $pages = max(1, (int)ceil($total / static::POSTS_PER_PAGE));
        $pageNo = min($pageNo, $pages);

        $posts = Capsule::table('forum_post')
            ->leftJoin('user', 'user.id', '=', 'forum_post.user_id')
            ->where('topic_id', $topic->id)
            ->orderBy('forum_post.id')
            ->offset(($pageNo - 1) * static::POSTS_PER_PAGE)->limit(static::POSTS_PER_PAGE)
            ->get(['forum_post.*', 'user.name AS author']);
        $firstPostId = (int)Capsule::table('forum_post')->where('topic_id', $topic->id)->min('id');

        $postsHtml = '';
        foreach($posts as $post) {
            $own = $userId > 0 && $userId === (int)$post->user_id;
            $controls = '';
            if(($own && !$topic->locked) || $staff) {
                $controls .= ' <a href="' . $base . 'forums/edit-post/id%3D' . (int)$post->id . '/">edit</a>';
                if((int)$post->id !== $firstPostId) {
                    $controls .= '<form class="forum-inline-form" action="' . $base . 'forums/delete-post/" method="POST">'
                        . '<input type="hidden" name="id" value="' . (int)$post->id . '" />'
                        . '<label class="checkbox-label"><input type="checkbox" name="confirm" value="1" required />sure?</label>'
                        . '<button class="btn-link" type="submit">delete</button></form>';
                }
            }
            $edited = !empty($post->updated_at)
                ? '<p class="forum-edited">Last edited ' . $this->formatDate($post->updated_at) . '</p>' : '';
            $postsHtml .= '<div class="forum-post">'
                . '<div class="forum-post-head"><b>' . Strings::displayText((string)($post->author ?? 'Unknown')) . '</b>'
                . ' &middot; ' . $this->formatDate($post->created_at) . $controls . '</div>'
                . '<div class="forum-post-body">' . BBCode::toHtml($post->body) . $edited . '</div>'
                . '</div>';
        }

        // Reply form, or the reason there isn't one.
        if($topic->locked && !$staff) {
            $reply = '<p><i>This topic is locked.</i></p>';
        }
        else if($userId === 0) {
            $reply = '<p><a href="' . $base . 'sign-in/">Sign in</a> to reply.</p>';
        }
        else {
            $reply = '<div class="account-panel"><h3>Reply</h3>'
                . '<form action="' . $base . 'forums/reply/" method="POST">'
                . '<input type="hidden" name="id" value="' . (int)$topic->id . '" />'
                . '<div class="form-group"><label for="reply-body">Message</label><textarea id="reply-body" class="form-control forum-editor" name="body" maxlength="20000" required></textarea></div>'
                . '<p class="auth-hint">You can use [b]bold[/b], [i]italic[/i], [u]underline[/u], [quote], [code] and [url=https://...]links[/url].</p>'
                . '<div class="form-actions"><button class="btn btn-primary" type="submit">Post reply</button></div>'
                . '</form></div>';
        }

        // Moderation and owner controls.
        $controls = '';
        if($staff) {
            $controls .= '<form class="forum-inline-form" action="' . $base . 'forums/lock-topic/" method="POST">'
                . '<input type="hidden" name="id" value="' . (int)$topic->id . '" />'
                . '<button class="btn" type="submit">' . ($topic->locked ? 'Unlock' : 'Lock') . '</button></form> '
                . '<form class="forum-inline-form" action="' . $base . 'forums/sticky-topic/" method="POST">'
                . '<input type="hidden" name="id" value="' . (int)$topic->id . '" />'
                . '<button class="btn" type="submit">' . ($topic->sticky ? 'Unsticky' : 'Sticky') . '</button></form> ';
        }
        if($staff || ($userId > 0 && $userId === (int)$topic->user_id)) {
            $controls .= '<form class="forum-inline-form" action="' . $base . 'forums/delete-topic/" method="POST">'
                . '<input type="hidden" name="id" value="' . (int)$topic->id . '" />'
                . '<label class="checkbox-label"><input type="checkbox" name="confirm" value="1" required />I\'m sure</label>'
                . '<button class="btn btn-danger" type="submit">Delete topic</button></form>';
        }

        $page = $state->view->getViewLayout('forum' . DIRECTORY_SEPARATOR . 'topic.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', $this->buildAlertsHtml($state));
        $page->injectHtml('board.id', (int)$topic->board_id);
        $page->injectHtml('board.name', Strings::displayText((string)($board->name ?? 'Forums')));
        $page->injectHtml('topic.title', Strings::displayText($topic->title)
            . ($topic->locked ? ' <i>[locked]</i>' : ''));
        $page->injectHtml('posts', $postsHtml);
        $page->injectHtml('pager', $this->buildPagerHtml($state, 'forums/topic/id%3D' . (int)$topic->id . '/', $pageNo, $pages));
        $page->injectHtml('reply', $reply);
        $page->injectHtml('controls', $controls);
        $state->view->setPage($page->get('html'), [], $topic->title);
    }

    /**
     * New-topic form.
     *
     * @param object      $state    Application state.
     * @param object|null $response Optional response with errors from a failed POST.
     */
    protected function httpGetNewTopic($state, $response = null) {
        $state = $this->requireUser();
        $board = Capsule::table('forum_board')->where('id', (int)$state->url->getVariable('id'))->first();
        if(empty($board->id)) {
            return $this->redirectWithFlash($state, 'forums/', 'err', 'That board does not exist.');
        }
        if($board->locked && !$this->isStaff()) {
            return $this->redirectWithFlash($state, 'forums/board/id%3D' . (int)$board->id . '/', 'err', 'Only staff can start topics on this board.');
        }
        $page = $state->view->getViewLayout('forum' . DIRECTORY_SEPARATOR . 'new-topic.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', $this->buildAlertsHtml($state, $response));
        $page->injectHtml('board.id', (int)$board->id);
        $page->injectHtml('board.name', Strings::displayText($board->name));
        $state->view->setPage($page->get('html'), [], 'New topic - ' . $board->name);
    }

    /**
     * Create a topic with its opening post.
     *
     * @param object $state Application state.
     */
    protected function httpPostNewTopic($state) {
        $state = $this->requireUser();
        $response = $this->getBlankResponse();
        $boardId = (int)$state->url->getVariable('id');
        try {
            $board = Capsule::table('forum_board')->where('id', $boardId)->first();
            if(empty($board->id)) {
                throw new \Exception('That board does not exist.');
            }
            if($board->locked && !$this->isStaff()) {
                throw new \Exception('Only staff can start topics on this board.');
            }
            $title = trim((string)filter_input(INPUT_POST, 'title', FILTER_UNSAFE_RAW));
            $body  = trim((string)filter_input(INPUT_POST, 'body',  FILTER_UNSAFE_RAW));
            if($title === '' || strlen($title) > static::TITLE_MAX) {
                throw new \Exception('Topic titles must be 1 to ' . static::TITLE_MAX . ' characters.');
            }
            if($body === '' || strlen($body) > static::BODY_MAX) {
                throw new \Exception('Posts must be 1 to ' . number_format(static::BODY_MAX) . ' characters.');
            }
            $userId = (int)$state->activeUser->id;
            $now = time();
            $topicId = null;
            Capsule::connection()->transaction(function() use($board, $userId, $title, $body, $now, &$topicId) {
                $topicId = Capsule::table('forum_topic')->insertGetId([
                    'board_id' => (int)$board->id, 'user_id' => $userId, 'title' => $title,
                    'posts' => 1, 'created_at' => $now, 'last_post_at' => $now,
                ]);
                Capsule::table('forum_post')->insert([
                    'topic_id' => $topicId, 'user_id' => $userId, 'body' => $body, 'created_at' => $now,
                ]);
                Capsule::table('forum_board')->where('id', (int)$board->id)->increment('topics', 1, ['posts' => (int)$board->posts + 1]);
            });
        }
        catch(\Exception $e) {
            $response->errors[] = $this->getError($e);
            return $this->httpGetNewTopic($state, $response);
        }
        return $state->app->redirect($state->url->getBaseUrl() . 'forums/topic/id%3D' . (int)$topicId . '/');
    }

    /**
     * Add a reply to a topic.
     *
     * @param object $state Application state.
     */
    protected function httpPostReply($state) {
        $state = $this->requireUser();
        $topicId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            $topic = Capsule::table('forum_topic')->where('id', $topicId)->first();
            if(empty($topic->id)) {
                throw new \Exception('That topic does not exist.');
            }
            if($topic->locked && !$this->isStaff()) {
                throw new \Exception('This topic is locked.');
            }
            $body = trim((string)filter_input(INPUT_POST, 'body', FILTER_UNSAFE_RAW));
            if($body === '' || strlen($body) > static::BODY_MAX) {
                throw new \Exception('Posts must be 1 to ' . number_format(static::BODY_MAX) . ' characters.');
            }
            $userId = (int)$state->activeUser->id;
            $now = time();
            Capsule::connection()->transaction(function() use($topic, $userId, $body, $now) {
                Capsule::table('forum_post')->insert([
                    'topic_id' => (int)$topic->id, 'user_id' => $userId, 'body' => $body, 'created_at' => $now,
                ]);
                Capsule::table('forum_topic')->where('id', (int)$topic->id)
                    ->update(['posts' => (int)$topic->posts + 1, 'last_post_at' => $now]);
                Capsule::table('forum_board')->where('id', (int)$topic->board_id)->increment('posts');
            });
        }
        catch(\Exception $e) {
            return $this->redirectWithFlash($state, $topicId > 0 ? 'forums/topic/id%3D' . $topicId . '/' : 'forums/', 'err', $this->getError($e));
        }
        // Land on the last page, where the new reply is.
        $count = Capsule::table('forum_post')->where('topic_id', $topicId)->count();
        $lastPage = max(1, (int)ceil($count / static::POSTS_PER_PAGE));
        return $state->app->redirect($state->url->getBaseUrl() . 'forums/topic/id%3D' . $topicId . '/' . ($lastPage > 1 ? 'page%3D' . $lastPage . '/' : ''));
    }

    /**
     * Load a post the active user may modify (author of an unlocked topic,
     * or staff).
     *
     * @param  object $state  Application state.
     * @param  int    $postId Post id.
     * @return object         [post, topic] rows.
     * @throws \Exception     When the post is missing or not modifiable.
     */
    protected function loadEditablePost($state, $postId) {
        $post = $postId > 0 ? Capsule::table('forum_post')->where('id', $postId)->first() : null;
        if(empty($post->id)) {
            throw new \Exception('That post does not exist.');
        }
        $topic = Capsule::table('forum_topic')->where('id', $post->topic_id)->first();
        $own = (int)$post->user_id === (int)$state->activeUser->id;
        if(!$this->isStaff() && (!$own || !empty($topic->locked))) {
            throw new \Exception(!empty($topic->locked) && $own ? 'This topic is locked.' : 'You can only change your own posts.');
        }
        return (object)['post' => $post, 'topic' => $topic];
    }

    /**
     * Edit-post form.
     *
     * @param object      $state    Application state.
     * @param object|null $response Optional response with errors from a failed POST.
     */
    protected function httpGetEditPost($state, $response = null) {
        $state = $this->requireUser();
        try {
            $found = $this->loadEditablePost($state, (int)$state->url->getVariable('id'));
        }
        catch(\Exception $e) {
            return $this->redirectWithFlash($state, 'forums/', 'err', $this->getError($e));
        }
        $page = $state->view->getViewLayout('forum' . DIRECTORY_SEPARATOR . 'edit-post.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', $this->buildAlertsHtml($state, $response));
        $page->injectHtml('post.id', (int)$found->post->id);
        $page->injectHtml('post.body', Strings::displayText($found->post->body));
        $page->injectHtml('topic.id', (int)$found->topic->id);
        $page->injectHtml('topic.title', Strings::displayText((string)$found->topic->title));
        $state->view->setPage($page->get('html'), [], 'Edit post');
    }

    /**
     * Save an edited post, stamping the edit.
     *
     * @param object $state Application state.
     */
    protected function httpPostEditPost($state) {
        $state = $this->requireUser();
        $postId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            $found = $this->loadEditablePost($state, $postId);
            $body = trim((string)filter_input(INPUT_POST, 'body', FILTER_UNSAFE_RAW));
            if($body === '' || strlen($body) > static::BODY_MAX) {
                throw new \Exception('Posts must be 1 to ' . number_format(static::BODY_MAX) . ' characters.');
            }
            Capsule::table('forum_post')->where('id', (int)$found->post->id)->update([
                'body' => $body, 'updated_at' => time(), 'edited_by' => (int)$state->activeUser->id,
            ]);
        }
        catch(\Exception $e) {
            // Land back on the topic when we know which one it was — being
            // dumped at the forums index loses the reader's place.
            return $this->redirectWithFlash($state, !empty($found->topic->id) ? 'forums/topic/id%3D' . (int)$found->topic->id . '/' : 'forums/', 'err', $this->getError($e));
        }
        return $this->redirectWithFlash($state, 'forums/topic/id%3D' . (int)$found->topic->id . '/', 'msg', 'Your post has been updated.');
    }

    /**
     * Delete a single post (never a topic's opening post).
     *
     * @param object $state Application state.
     */
    protected function httpPostDeletePost($state) {
        $state = $this->requireUser();
        $postId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $found = null;
        try {
            if((int)filter_input(INPUT_POST, 'confirm', FILTER_VALIDATE_INT) !== 1) {
                throw new \Exception('Tick the confirmation box to delete this post.');
            }
            $found = $this->loadEditablePost($state, $postId);
            $firstPostId = (int)Capsule::table('forum_post')->where('topic_id', $found->topic->id)->min('id');
            if((int)$found->post->id === $firstPostId) {
                throw new \Exception('The opening post cannot be deleted on its own — delete the topic instead.');
            }
            Capsule::connection()->transaction(function() use($found) {
                Capsule::table('forum_post')->where('id', (int)$found->post->id)->delete();
                $last = Capsule::table('forum_post')->where('topic_id', (int)$found->topic->id)->max('created_at');
                Capsule::table('forum_topic')->where('id', (int)$found->topic->id)->update([
                    'posts' => max(0, (int)$found->topic->posts - 1),
                    'last_post_at' => (int)$last,
                ]);
                Capsule::table('forum_board')->where('id', (int)$found->topic->board_id)
                    ->where('posts', '>', 0)->decrement('posts');
            });
        }
        catch(\Exception $e) {
            // Same as edit: stay on the topic when it is known.
            return $this->redirectWithFlash($state, !empty($found->topic->id) ? 'forums/topic/id%3D' . (int)$found->topic->id . '/' : 'forums/', 'err', $this->getError($e));
        }
        return $this->redirectWithFlash($state, 'forums/topic/id%3D' . (int)$found->topic->id . '/', 'msg', 'The post has been deleted.');
    }

    /**
     * Delete a whole topic. The author may do this only while nobody else
     * has replied; staff always can.
     *
     * @param object $state Application state.
     */
    protected function httpPostDeleteTopic($state) {
        $state = $this->requireUser();
        $topicId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            if((int)filter_input(INPUT_POST, 'confirm', FILTER_VALIDATE_INT) !== 1) {
                throw new \Exception('Tick the confirmation box to delete this topic.');
            }
            $topic = $topicId > 0 ? Capsule::table('forum_topic')->where('id', $topicId)->first() : null;
            if(empty($topic->id)) {
                throw new \Exception('That topic does not exist.');
            }
            $userId = (int)$state->activeUser->id;
            if(!$this->isStaff()) {
                if((int)$topic->user_id !== $userId) {
                    throw new \Exception('You can only delete your own topics.');
                }
                $others = Capsule::table('forum_post')->where('topic_id', $topic->id)->where('user_id', '!=', $userId)->count();
                if($others > 0) {
                    throw new \Exception('Other people have replied to this topic — only staff can delete it now.');
                }
            }
            Capsule::connection()->transaction(function() use($topic) {
                $posts = Capsule::table('forum_post')->where('topic_id', (int)$topic->id)->count();
                Capsule::table('forum_post')->where('topic_id', (int)$topic->id)->delete();
                Capsule::table('forum_topic')->where('id', (int)$topic->id)->delete();
                $board = Capsule::table('forum_board')->where('id', (int)$topic->board_id)->first();
                if(!empty($board->id)) {
                    Capsule::table('forum_board')->where('id', (int)$board->id)->update([
                        'topics' => max(0, (int)$board->topics - 1),
                        'posts'  => max(0, (int)$board->posts - $posts),
                    ]);
                }
            });
        }
        catch(\Exception $e) {
            return $this->redirectWithFlash($state, $topicId > 0 ? 'forums/topic/id%3D' . $topicId . '/' : 'forums/', 'err', $this->getError($e));
        }
        return $this->redirectWithFlash($state, 'forums/board/id%3D' . (int)$topic->board_id . '/', 'msg', 'The topic has been deleted.');
    }

    /**
     * Toggle a topic's locked flag (staff).
     *
     * @param object $state Application state.
     */
    protected function httpPostLockTopic($state) {
        return $this->toggleTopicFlag($state, 'locked');
    }

    /**
     * Toggle a topic's sticky flag (staff).
     *
     * @param object $state Application state.
     */
    protected function httpPostStickyTopic($state) {
        return $this->toggleTopicFlag($state, 'sticky');
    }

    /**
     * Shared staff toggle for topic flags.
     *
     * @param object $state Application state.
     * @param string $flag  'locked' or 'sticky'.
     */
    protected function toggleTopicFlag($state, $flag) {
        $state = $this->requireUser();
        $topicId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            if(!$this->isStaff()) {
                throw new \Exception('Only staff can do that.');
            }
            $topic = $topicId > 0 ? Capsule::table('forum_topic')->where('id', $topicId)->first() : null;
            if(empty($topic->id)) {
                throw new \Exception('That topic does not exist.');
            }
            Capsule::table('forum_topic')->where('id', (int)$topic->id)->update([$flag => $topic->{$flag} ? 0 : 1]);
        }
        catch(\Exception $e) {
            return $this->redirectWithFlash($state, 'forums/', 'err', $this->getError($e));
        }
        return $state->app->redirect($state->url->getBaseUrl() . 'forums/topic/id%3D' . $topicId . '/');
    }

    /**
     * Create a board (staff).
     *
     * @param object $state Application state.
     */
    protected function httpPostCreateBoard($state) {
        return $this->saveBoard($state, true);
    }

    /**
     * Edit a board (staff).
     *
     * @param object $state Application state.
     */
    protected function httpPostEditBoard($state) {
        return $this->saveBoard($state, false);
    }

    /**
     * Shared create/edit for boards.
     *
     * @param object $state  Application state.
     * @param bool   $create Whether this is a create (vs edit).
     */
    protected function saveBoard($state, $create) {
        $state = $this->requireUser();
        try {
            if(!$this->isStaff()) {
                throw new \Exception('Only staff can manage boards.');
            }
            $name = trim((string)filter_input(INPUT_POST, 'name', FILTER_UNSAFE_RAW));
            $description = trim((string)filter_input(INPUT_POST, 'description', FILTER_UNSAFE_RAW));
            if($name === '' || strlen($name) > 80) {
                throw new \Exception('Board names must be 1 to 80 characters.');
            }
            $values = [
                'name' => $name,
                'description' => substr($description, 0, 255),
                'sort' => (int)filter_input(INPUT_POST, 'sort', FILTER_VALIDATE_INT),
                'locked' => filter_input(INPUT_POST, 'locked') ? 1 : 0,
            ];
            if($create) {
                Capsule::table('forum_board')->insert($values);
            }
            else {
                $id = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                if(Capsule::table('forum_board')->where('id', $id)->update($values) === 0
                        && !Capsule::table('forum_board')->where('id', $id)->exists()) {
                    throw new \Exception('That board does not exist.');
                }
            }
        }
        catch(\Exception $e) {
            return $this->redirectWithFlash($state, 'forums/', 'err', $this->getError($e));
        }
        return $this->redirectWithFlash($state, 'forums/', 'msg', $create ? 'Board created.' : 'Board updated.');
    }

    /**
     * Delete a board with everything on it (staff).
     *
     * @param object $state Application state.
     */
    protected function httpPostDeleteBoard($state) {
        $state = $this->requireUser();
        try {
            if(!$this->isStaff()) {
                throw new \Exception('Only staff can manage boards.');
            }
            if((int)filter_input(INPUT_POST, 'confirm', FILTER_VALIDATE_INT) !== 1) {
                throw new \Exception('Tick the confirmation box to delete this board.');
            }
            $id = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $board = $id > 0 ? Capsule::table('forum_board')->where('id', $id)->first() : null;
            if(empty($board->id)) {
                throw new \Exception('That board does not exist.');
            }
            Capsule::connection()->transaction(function() use($board) {
                $topicIds = Capsule::table('forum_topic')->where('board_id', (int)$board->id)->pluck('id')->all();
                if(!empty($topicIds)) {
                    Capsule::table('forum_post')->whereIn('topic_id', $topicIds)->delete();
                    Capsule::table('forum_topic')->whereIn('id', $topicIds)->delete();
                }
                Capsule::table('forum_board')->where('id', (int)$board->id)->delete();
            });
        }
        catch(\Exception $e) {
            return $this->redirectWithFlash($state, 'forums/', 'err', $this->getError($e));
        }
        return $this->redirectWithFlash($state, 'forums/', 'msg', 'Board "' . $board->name . '" and all its topics have been deleted.');
    }

}
