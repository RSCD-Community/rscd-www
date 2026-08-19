/*
 * Fullscreen glue for whichever page is hosting the client.
 *
 * Two pages host it -- this module's own web/index.html, and rscd-www's
 * /play/browser, which puts the same canvas inside the site's frame. The
 * behaviour has to be identical on both, so it lives in one file that both
 * load rather than in a script block each of them carries a copy of.
 *
 * Fullscreen is requested here rather than from the client, because browsers
 * only honour the request inside a real user gesture -- a click listener
 * qualifies, a call from the game loop does not. The client watches for the
 * resulting fullscreenchange and resizes itself; nothing here touches the
 * canvas.
 *
 * Expects two elements: #rscd-stage (goes fullscreen, and holds the canvas)
 * and #rscd-fs (the button). It does nothing at all if either is missing, so
 * a page that wants the client without the button simply leaves it out.
 */
(function () {
    var stage = document.getElementById('rscd-stage');
    var btn = document.getElementById('rscd-fs');
    if (!stage || !btn) { return; }

    function active() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }
    function enter() {
        var fn = stage.requestFullscreen || stage.webkitRequestFullscreen;
        if (fn) { fn.call(stage); }
    }
    function leave() {
        var fn = document.exitFullscreen || document.webkitExitFullscreen;
        if (fn) { fn.call(document); }
    }
    function label() {
        btn.textContent = active() ? 'Exit fullscreen' : 'Fullscreen';
    }

    btn.addEventListener('click', function () {
        if (active()) { leave(); } else { enter(); }
        /* Hand the keyboard straight back to the game: the button keeps focus
           after a click, and then space and enter would work it again instead
           of reaching the client. Game keys are bound on window, so blurring
           costs nothing. */
        btn.blur();
    });
    document.addEventListener('fullscreenchange', label);
    document.addEventListener('webkitfullscreenchange', label);
    label();

    /*
     * The Menu button -- F2, for a player with no F-keys to press.
     *
     * It sends the key rather than reaching into the client: DomEvents already
     * listens for keydown on window and turns F2 into the action the game
     * reads, so a synthetic event goes down exactly the path a real keyboard
     * does and this file needs to know nothing about what F2 means. Both
     * halves of the press, because that is what a real one looks like.
     *
     * It lives in the strip with the fullscreen button, which is inside the
     * element that goes fullscreen -- so unlike a control placed on the page
     * around the canvas, it is still there in fullscreen, where a phone player
     * is most likely to want it.
     */
    var menu = document.getElementById('rscd-menu');
    if (menu) {
        menu.addEventListener('click', function () {
            ['keydown', 'keyup'].forEach(function (type) {
                window.dispatchEvent(new KeyboardEvent(type, { key: 'F2', bubbles: true }));
            });
            /* Same reason as the fullscreen button: a button that keeps focus
               would then answer space and enter instead of the game. */
            menu.blur();
        });
    }

    /*
     * There was a Chat button here, outside the canvas, to summon the
     * on-screen keyboard for a player with no real one. It is gone: the game
     * already draws somewhere that means "your message goes here" -- the '*'
     * cursor and part-typed message along the bottom -- and tapping that is
     * both more obvious and one less thing wedged into the page around the
     * canvas. See mudclient.isChatEntryArea and rscweb.web.MobileKeyboard.
     */
})();
