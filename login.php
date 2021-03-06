<?php

require "api/config.php";
require "api/globals.php";

/**
 * Temporary solution for disabling this page for logged in users.
 */

// Composer Autoloader
$loader = require 'api/vendor/autoload.php';
$loader->add("Directus", dirname(__FILE__) . "/api/core/");

if(\Directus\Auth\Provider::loggedIn()) {
    header('Location: ' . DIRECTUS_PATH );
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no,maximum-scale=1.0">
  <title>directus</title>
  <!-- Application styles. -->
  <link rel="shortcut icon" href="favicon.ico">
  <link href='http://fonts.googleapis.com/css?family=Open+Sans:300italic,400italic,600italic,700italic,800italic,400,300,600,700,800' rel='stylesheet' type='text/css'>
  <link rel="stylesheet" href="assets/css/index.css">
  <style>
    html,body {
      /*background-color: #bbbbbb;*/
      margin:0;
      padding:0;
      height: 100%;
      width: 100%;
      background: -webkit-linear-gradient(left top, #eeeeee , #dddddd); /* For Safari 5.1 to 6.0 */
      background: -o-linear-gradient(bottom right, #eeeeee, #dddddd); /* For Opera 11.1 to 12.0 */
      background: -moz-linear-gradient(bottom right, #eeeeee, #dddddd); /* For Firefox 3.6 to 15 */
      background: linear-gradient(to bottom right, #eeeeee , #dddddd); /* Standard syntax (must be last) */
    }
    /* .login-panel { background-color:rgba(255,255,255,0.4); padding:20px; width:372px; box-shadow: 0px 1px 10px 0px rgba(0,0,0,0.05); position: absolute; left:50%; top:50%; margin-left:-208px; margin-top:-245px;} */
    /* .login-panel p.error { padding: 15px 10px 0; margin: 0; color: red; } */
    /* .login-panel p.message { padding: 15px 10px 0; margin: 0; color: green; } */
    /*
input[type="text"], input[type="password"] {font-size:16px; width:360px; border:0;  margin-bottom:20px; height:30px; line-height:30px;}
    input[type="submit"], button { display:block; width:375px; }
*/
    /* label {margin-bottom:20px; font-weight:normal;} */
    /* h2 {font-size:26px; margin-bottom:20px; margin-top:0px;} */
  </style>
</head>

<body class="font-primary">

<!-- Main container. -->
<form action="<?= DIRECTUS_PATH ?>api/1/auth/login" method="post" class="login-box" autocomplete="off">
  <div class='login-panel'>
    <p class="">
    <input type="text" name="email" placeholder="Email Address" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off" />
    </p>
    <p class="">
      <input type="password" name="password" placeholder="Password" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off" />
      <span id="forgot-password" title="Forgot Password" class="btn btn-primary"></span>
    </p>
    <p class="clearfix no-margin">
      <button type="submit" class="btn primary">Sign in</button>
    </p>
    <!--<label class="checkbox">
        <input type="checkbox" name="remember" /> Keep me logged in on this computer
    </label>-->
  </div>
  <p class="error" style="display:none;"></p>
  <p class="message" style="display:none;"></p>
  <div class="directus-version">Version: <?php echo(DIRECTUS_VERSION) ?></div>
  <!-- <button type="submit" class="btn btn-primary">Sign in</button> -->
  <!-- <button id="forgot-password" class="btn btn-primary">Forgot Password</button> -->
</form>

<!-- Javascripts -->
<script type="text/javascript" src="<?= DIRECTUS_PATH ?>assets/js/libs/jquery.js"></script>
<script type="text/javascript">
$(function(){

  var $login_message = $('p.message');
  var $login_error = $('p.error');

  function message(message, error) {
    error = error || false;
    if(error) {
      $login_error.html(message);
      $login_error.show();
    } else {
      $login_message.html(message);
      $login_message.show();
    }
  }

  <?php if(isset($_GET['inactive'])) {echo 'message("Logged out due to inactivity", true);';}?>

  function clear_messages() {
    $login_error.hide();
    $login_message.hide();
  }

  $('#forgot-password').bind('click', function(e){
    e.preventDefault();
    clear_messages();
    var $form = $(this).closest('form'),
        email = $.trim($form.find('input[name=email]').val());
    if(email.length == 0) {
      message("Please enter a valid email address", true);
      return false;
    }
    if(confirm('Are you sure you want to reset your password?')) {
      $.ajax('<?= DIRECTUS_PATH . 'api/' . API_VERSION . '/auth/forgot-password' ?>', {
        data: { email: email },
        dataType: 'json',
        type: 'POST',
        success: function(data, textStatus, jqXHR) {
          if(!data.success) {
            var errorMessage = "Oops an error occurred!";
            if(data.message) {
                errorMessage = data.message;
            }
            message(errorMessage, true);
            return;
          }
          message("Temporary password sent to your email address")
        },
        error: function(jqXHR, textStatus, errorThrown) {
          message("Server error occurred!", true);
        }
      });
    }
  });

  $('form').bind('submit', function(e){
    e.preventDefault();
    clear_messages();
    var email = $.trim($(this).find('input[name=email]').val()),
        password = $.trim($(this).find('input[name=password]').val());

    if(email.length == 0 || password.length == 0) {
      return message("We need both!", true);
    }

    $.ajax('<?= DIRECTUS_PATH . 'api/' . API_VERSION . '/auth/login' ?>', {
      data: { email: email, password: password },
      dataType: 'json',
      type: 'POST',
      success: function(data, textStatus, jqXHR) {

        // Default path
        var path = 'users';

        // Silent error if the path is not avalible
        try {
          var lastPage = JSON.parse(data.last_page);
          path = lastPage.path;
        } catch(e) {
          console.warn('Parsing path object failed', data.last_page);
        }

        if(!data.success) {
          message(data.message, true);
          return;
        }

        window.location = "<?= DIRECTUS_PATH ?>"+path;

      },
      error: function(jqXHR, textStatus, errorThrown) {
        message("Server error occurred!", true);
      }
    });
  });

});
</script>
</body>
</html>