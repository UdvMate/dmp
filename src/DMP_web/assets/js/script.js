document.getElementById('register-form').addEventListener('submit', function(e) {
    const password = document.getElementById('password').value;
    if (password.length < 8) {
        e.preventDefault();
        alert('Password must be at least 8 characters!');
    }
});



