<?php // Decorative left panel shared by the login and signup pages (static content only). ?>
<section class="auth-side" aria-hidden="true">
  <div class="auth-side-inner">
    <p class="auth-brand"><?= e(APP_NAME) ?></p>
    <h2>See exactly what changed.</h2>
    <p class="auth-lede">Commit your code from the browser and review every added and removed line.</p>

    <div class="diff-card">
      <div class="diff-head">
        <span class="diff-hash">4f2a9c1</span>
        <span class="diff-msg">Fix navbar overflow</span>
      </div>
      <div class="diff-file">style.css</div>
      <pre class="diff-body"><span class="ctx">  .nav {</span>
<span class="del">-   overflow: hidden;</span>
<span class="add">+   overflow-x: auto;</span>
<span class="add">+   white-space: nowrap;</span>
<span class="ctx">  }</span></pre>
    </div>
  </div>
</section>
