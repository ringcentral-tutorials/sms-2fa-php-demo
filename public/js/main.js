const OK = 0
const FAILED = 1
const LOCKED = 2
const INVALID = 3
const UNKNOWN = 4
var email = ""
function init() {
  $("#info_label").hide()
  var url = "engine.php?canlogin";
  $.get(url
  ).done(function(response){
    if (response.error == 1){
      window.location.assign("signup.html")
    }
  }).fail(function(response){
    alert(JSON.stringify(response));
  });
}

function getSeed(){
  $("#info_label").hide()
  $("#info_label").html("")
  var url = "engine.php?getseed";
  $.get(url
  ).done(function(response){
    if (response.error == 0) {
      var temp = CryptoJS.SHA256($("#password").val()).toString(CryptoJS.enc.Hex);
      var hashed = CryptoJS.SHA256(temp+response.seed).toString(CryptoJS.enc.Hex);
      login(response.id, hashed)
    }
  }).fail(function(response){
    alert(JSON.stringify(response));
  });
}

function login(id, hashed){
  var url = "engine.php?login";
  email = $("#username").val()
  var data = {
    username: email,
    password: hashed,
    id: id
  }
  $.post(url, data
  ).done(function(response){
    if (response.error == OK){
      window.location.assign("about.html")
    }else if (response.error == FAILED){
      $("#info_label").show()
      $("#info_label").html(response.message)
    }else if (response.error == LOCKED){
      $("#login").hide()
      $("#info_label_vc").html(response.message)
      $("#info_label_vc").show()
      $('#verification').show()
    }
  }).fail(function(response){
    alert(JSON.stringify(response));
  });
}

function signup(){
  var url = "engine.php?signup";
  var temp = $("#password").val()
  if (temp == ""){
    alert("Missing password.")
    return
  }
  if ($("#email").val() == ""){
    alert("Missing email address.")
    return
  }
  if ($("#phoneno").val() == ""){
    alert("Missing phone number.")
    return
  }
  var hashed = CryptoJS.SHA256(temp, "").toString(CryptoJS.enc.Hex);

  var data = {
    email: $("#email").val(),
    password: hashed,
    phoneno: $("#phoneno").val(),
    fname: $("#fname").val(),
    lname: $("#lname").val()
  }

  $.post(url, data
  ).done(function(response){
    if (response.error == OK){
      window.location.assign("index.html")
    }else{
      alert(response.message)
    }
  }).fail(function(response){
    alert(response.responseText);
  });
}

function verifyPasscode() {
  var url = "engine.php?verifypass";
  if (email != ""){
    var data = {
      username: email,
      passcode: $("#passcode").val()
    }
    $.post(url, data
    ).done(function(response){
      if (response.error == OK){
        $('#verification').hide()
        $("#login").show()
        $("#info_label").html(response.message)
        $("#info_label").show()
      }else{
        $("#info_label_vc").html(response.message)
        $("#info_label_vc").show()
      }
    }).fail(function(response){
        alert(JSON.stringify(response));
    });
  }
}

function resendCode(){
  var url = "engine.php?resendcode";
  if (email != ""){
    var data = {
      username: email
    }

    $.post(url, data
    ).done(function(response){
      $("#info_label_cp").html(response.message)
      $("#info_label_cp").show()
      $("#info_label_vc").html(response.message)
      $("#info_label_vc").show()
    }).fail(function(response){
      alert(JSON.stringify(response));
    });
  }
}

function resetPassword() {
  $("#login").hide()
  $("#resetpwd").show()
  $("#verification_cp").hide()
}

function resetPwd() {
  email = $("#username_cp").val()
  var url = "engine.php?resetpwd";
  if (email != ""){
    var data = {
      username: email
    }
    if ($("#verification_cp").is(":visible")){
      var temp = $("#password_cp").val()
      var hashed = CryptoJS.SHA256(temp, "").toString(CryptoJS.enc.Hex);
      data.pwd = hashed
      data.code = $("#code_cp").val()
    }
    $.post(url, data
    ).done(function(response){
      if (response.error == OK){
          $("#resetpwd").hide()
          $("#info_label").show()
          $("#login").show()
      }else if (response.error == UNKNOWN){
          $("#info_label_cp").html(response.message)
      }else{
          $("#verification_cp").show()
          $("#info_label_cp").html(response.message)
          $("#info_label_vc").html(response.message)
      }
    }).fail(function(response){
      alert(JSON.stringify(response));
    });
  }
}
