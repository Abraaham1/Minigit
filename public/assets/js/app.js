// Show/hide password buttons
document.querySelectorAll('[data-reveal]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.getElementById(btn.dataset.reveal);
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
    btn.setAttribute('aria-pressed', String(show));
  });
});

// Signup: tell the user right away when the two passwords differ.
// (Convenience only: the server checks this again.)
var signup = document.getElementById('signup-form');
if (signup) {
  var pw = signup.elements['password'];
  var confirmPw = signup.elements['confirm_password'];
  var check = function () {
    confirmPw.setCustomValidity(
      confirmPw.value && confirmPw.value !== pw.value ? 'Passwords do not match.' : ''
    );
  };
  pw.addEventListener('input', check);
  confirmPw.addEventListener('input', check);
}

// Branch dropdowns reload the page when changed
document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
  sel.addEventListener('change', function () { sel.form.submit(); });
});

// Ask before dangerous actions: <form data-confirm="Are you sure?">
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
  form.addEventListener('submit', function (ev) {
    if (!window.confirm(form.dataset.confirm)) { ev.preventDefault(); }
  });
});

/* ------------------------------------------------------------------
   Upload page: pick or drop files and folders, send them to stage.php
   ------------------------------------------------------------------ */
(function () {
  var app = document.getElementById('upload-app');
  if (!app) { return; }

  var IGNORE = ['.git', 'node_modules', '.DS_Store', '.idea', '.vscode'];
  var MAX_FILE = 2 * 1024 * 1024;     // 2 MB per file (the server checks again)
  var MAX_TOTAL = 4 * 1024 * 1024;    // keeps the request under PHP's default 8 MB limit

  var selected = {};                  // path -> File
  var skipped = [];                   // names left out, with the reason
  var dropzone = document.getElementById('dropzone');
  var summary = document.getElementById('upload-summary');
  var list = document.getElementById('upload-list');
  var sendBtn = document.getElementById('upload-send');
  var errBox = document.getElementById('upload-error');

  function showError(msg) {
    errBox.textContent = msg;
    errBox.hidden = !msg;
  }

  function ignored(path) {
    return path.split('/').some(function (part) { return IGNORE.indexOf(part) !== -1; });
  }

  function addFile(path, file) {
    if (ignored(path)) { skipped.push(path + ' (ignored folder)'); return; }
    if (file.size > MAX_FILE) { skipped.push(path + ' (over 2 MB)'); return; }
    selected[path] = file;
  }

  function render() {
    var paths = Object.keys(selected).sort();
    var total = 0;
    paths.forEach(function (p) { total += selected[p].size; });

    list.innerHTML = '';
    paths.slice(0, 60).forEach(function (p) {
      var li = document.createElement('li');
      var name = document.createElement('span');
      name.textContent = p;
      var size = document.createElement('span');
      size.textContent = Math.max(1, Math.round(selected[p].size / 1024)) + ' KB';
      li.appendChild(name);
      li.appendChild(size);
      list.appendChild(li);
    });
    if (paths.length > 60) {
      var more = document.createElement('li');
      more.textContent = '... and ' + (paths.length - 60) + ' more files';
      list.appendChild(more);
    }

    var text = paths.length ? paths.length + (paths.length === 1 ? ' file' : ' files') + ' selected, ' + (total / 1024).toFixed(1) + ' KB.' : 'No files selected yet.';
    if (skipped.length) { text += ' Left out: ' + skipped.slice(0, 3).join(', ') + (skipped.length > 3 ? ' and ' + (skipped.length - 3) + ' more' : '') + '.'; }
    summary.textContent = text;

    if (total > MAX_TOTAL) {
      showError('These files add up to more than 4 MB. Upload them in smaller groups.');
      sendBtn.disabled = true;
    } else {
      showError('');
      sendBtn.disabled = paths.length === 0;
    }
  }

  // Files from the two file pickers. A chosen folder has webkitRelativePath: "folder/sub/file.txt".
  function addFromInput(files, stripTop) {
    Array.prototype.forEach.call(files, function (f) {
      var path = f.webkitRelativePath || f.name;
      if (stripTop && f.webkitRelativePath) { path = path.split('/').slice(1).join('/'); }
      if (path) { addFile(path, f); }
    });
    render();
  }
  document.getElementById('pick-files').addEventListener('change', function (e) { addFromInput(e.target.files, false); e.target.value = ''; });
  document.getElementById('pick-folder').addEventListener('change', function (e) { addFromInput(e.target.files, true); e.target.value = ''; });

  // Drag and drop (files and folders)
  function readAll(reader) {
    return new Promise(function (resolve, reject) {
      var all = [];
      (function next() {
        reader.readEntries(function (batch) {
          if (!batch.length) { resolve(all); return; }
          all = all.concat(Array.prototype.slice.call(batch));
          next();
        }, reject);
      })();
    });
  }
  function walk(entry, base) {
    if (entry.isFile) {
      return new Promise(function (resolve) {
        entry.file(function (f) { resolve([{ path: base + entry.name, file: f }]); }, function () { resolve([]); });
      });
    }
    return readAll(entry.createReader()).then(function (children) {
      return Promise.all(children.map(function (c) { return walk(c, base + entry.name + '/'); }));
    }).then(function (parts) { return [].concat.apply([], parts); });
  }

  ['dragenter', 'dragover'].forEach(function (name) {
    dropzone.addEventListener(name, function (e) { e.preventDefault(); dropzone.classList.add('dragover'); });
  });
  ['dragleave', 'drop'].forEach(function (name) {
    dropzone.addEventListener(name, function (e) { e.preventDefault(); dropzone.classList.remove('dragover'); });
  });
  dropzone.addEventListener('drop', function (e) {
    var items = e.dataTransfer.items;
    if (!items || !items.length || !items[0].webkitGetAsEntry) {
      addFromInput(e.dataTransfer.files, false);
      return;
    }
    var entries = [];
    for (var i = 0; i < items.length; i++) {
      var en = items[i].webkitGetAsEntry();
      if (en) { entries.push(en); }
    }
    var single = entries.length === 1 && entries[0].isDirectory;    // one folder dropped: use its contents
    Promise.all(entries.map(function (en) { return walk(en, ''); })).then(function (parts) {
      [].concat.apply([], parts).forEach(function (item) {
        var path = single ? item.path.split('/').slice(1).join('/') : item.path;
        if (path) { addFile(path, item.file); }
      });
      render();
    });
  });

  function toBase64(file) {
    return new Promise(function (resolve, reject) {
      var r = new FileReader();
      r.onload = function () { var s = r.result; resolve(s.substring(s.indexOf(',') + 1)); };
      r.onerror = reject;
      r.readAsDataURL(file);
    });
  }

  sendBtn.addEventListener('click', function () {
    var paths = Object.keys(selected);
    if (!paths.length) { return; }
    sendBtn.disabled = true;
    sendBtn.textContent = 'Reading files...';
    showError('');

    Promise.all(paths.map(function (p) {
      return toBase64(selected[p]).then(function (b64) { return { path: p, b64: b64 }; });
    })).then(function (files) {
      return fetch('stage.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.token },
        body: JSON.stringify({
          repo: app.dataset.repo,
          branch: app.dataset.branch,
          prefix: document.getElementById('prefix').value,
          sync: document.getElementById('sync').checked,
          files: files
        })
      });
    }).then(function (res) {
      return res.json();
    }).then(function (data) {
      if (data.ok) {
        window.location.href = app.dataset.next;
      } else {
        showError(data.error || 'The upload failed.');
        sendBtn.disabled = false;
        sendBtn.textContent = 'Review changes';
      }
    }).catch(function () {
      showError('The upload failed. If the files are large, raise post_max_size and upload_max_filesize in php.ini.');
      sendBtn.disabled = false;
      sendBtn.textContent = 'Review changes';
    });
  });

  render();
})();

/* ------------------------------------------------------------------
   Merge conflicts: buttons to keep ours, theirs or both for each conflict
   ------------------------------------------------------------------ */
document.querySelectorAll('.conflict-tools').forEach(function (tools) {
  var area = document.getElementById(tools.dataset.for);
  if (!area) { return; }

  function findBlocks(lines) {
    var blocks = [];
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].indexOf('<<<<<<< ') === 0) {
        var mid = -1, end = -1;
        for (var j = i + 1; j < lines.length; j++) {
          if (lines[j] === '=======' && mid === -1) { mid = j; }
          else if (lines[j].indexOf('>>>>>>> ') === 0 && mid !== -1) { end = j; break; }
        }
        if (end !== -1) {
          blocks.push({ start: i, mid: mid, end: end, ours: lines[i].substring(8), theirs: lines[end].substring(8) });
          i = end;
        }
      }
    }
    return blocks;
  }

  function resolve(index, mode) {
    var lines = area.value.split('\n');
    var b = findBlocks(lines)[index];
    if (!b) { return; }
    var ours = lines.slice(b.start + 1, b.mid);
    var theirs = lines.slice(b.mid + 1, b.end);
    var keep = mode === 'ours' ? ours : (mode === 'theirs' ? theirs : ours.concat(theirs));
    lines.splice.apply(lines, [b.start, b.end - b.start + 1].concat(keep));
    area.value = lines.join('\n');
    render();
  }

  function button(label, index, mode) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-quiet';
    b.textContent = label;
    b.addEventListener('click', function () { resolve(index, mode); });
    return b;
  }

  function render() {
    var blocks = findBlocks(area.value.split('\n'));
    tools.innerHTML = '';
    if (!blocks.length) {
      var done = document.createElement('p');
      done.className = 'muted small';
      done.textContent = 'No conflict markers are left in this file. You can still edit the text below.';
      tools.appendChild(done);
      return;
    }
    blocks.forEach(function (b, i) {
      var row = document.createElement('div');
      row.className = 'conflict-row';
      var label = document.createElement('strong');
      label.textContent = 'Conflict ' + (i + 1) + ' of ' + blocks.length + ':';
      row.appendChild(label);
      row.appendChild(button('Keep ' + b.ours, i, 'ours'));
      row.appendChild(button('Keep ' + b.theirs, i, 'theirs'));
      row.appendChild(button('Keep both', i, 'both'));
      tools.appendChild(row);
    });
  }

  area.addEventListener('input', render);
  render();
});
