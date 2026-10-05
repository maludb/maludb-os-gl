<?php /** A claim that was not taken. Data: message, token */ ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Not sent</title>
<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;background:#f5f5f5;color:#111}.box{max-width:480px;margin:10vh 16px;background:#fff;padding:24px;border-radius:8px;box-sizing:border-box}@media(min-width:520px){.box{margin-left:auto;margin-right:auto}}a{color:#0b5fff}</style></head>
<body id="claim-error-page"><div class="box"><h1 style="font-size:20px;margin-top:0">That was not sent.</h1><p id="claim-error-text"><?= e($message) ?></p><p><a href="/i/<?= e($token) ?>" id="claim-error-back">Back to the invoice</a></p></div></body></html>
