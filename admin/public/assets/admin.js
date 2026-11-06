// MailRunner Admin - copy buttons: <div class="copy-box"><pre|input class="copy-src">..</pre><button class="copy-btn">..</button></div>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.copy-btn');
    if (!btn) return;
    var src = btn.closest('.copy-box').querySelector('.copy-src');
    var text = (src.value !== undefined ? src.value : src.textContent).trim();
    var done = function () {
        btn.classList.add('copied');
        btn.setAttribute('title', 'Kopiert');
        setTimeout(function () { btn.classList.remove('copied'); btn.setAttribute('title', 'Kopieren'); }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);
    } else {
        var range = document.createRange(); range.selectNodeContents(src);
        var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
        document.execCommand('copy'); done();
    }
});

// Project form: show the fields of the selected type (receive | send) while creating a project
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('project-type');
    if (!sel) return;
    var show = function () {
        document.querySelectorAll('[data-type]').forEach(function (el) { el.hidden = el.dataset.type !== sel.value; });
    };
    sel.addEventListener('change', show);
    show();
});
