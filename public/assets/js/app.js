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
