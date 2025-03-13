using MySql.Data.MySqlClient;
using System;
using System.Text;
using System.Windows;
using System.Security.Cryptography;
using System.Diagnostics;

namespace dmp
{
    public partial class LoginWindow : Window
    {
        private string connectionString = "Server=localhost;Database=dmproject;UserID=root;";
        public bool IsLoggedIn { get; private set; } = false;

        public LoginWindow()
        {
            InitializeComponent();
        }

        // Hashing function (same as used during registration)
        private string HashPassword(string password)
        {
            using (var sha256 = SHA256.Create())
            {
                byte[] bytes = sha256.ComputeHash(Encoding.UTF8.GetBytes(password));
                return Convert.ToBase64String(bytes);
            }
        }

        private void LoginButton_Click(object sender, RoutedEventArgs e)
        {
            string username = UsernameTextBox.Text.Trim();
            string password = PasswordBox.Password.Trim();

            if (string.IsNullOrEmpty(username) || string.IsNullOrEmpty(password))
            {
                MessageBox.Show("Please enter both username and password.");
                return;
            }

            // Hash the entered password
            string hashedInputPassword = HashPassword(password);

            using (MySqlConnection connection = new MySqlConnection(connectionString))
            {
                try
                {
                    connection.Open();
                    // Query to check if the username and hashed password match
                    string query = "SELECT COUNT(*) FROM users WHERE username=@username AND password=@password";
                    MySqlCommand command = new MySqlCommand(query, connection);
                    command.Parameters.AddWithValue("@username", username);
                    command.Parameters.AddWithValue("@password", hashedInputPassword);

                    int userExists = Convert.ToInt32(command.ExecuteScalar());
                    if (userExists > 0)
                    {
                        IsLoggedIn = true;
                        MessageBox.Show("Login successful!");
                        this.Close();
                    }
                    else
                    {
                        MessageBox.Show("Invalid username or password.");
                    }
                }
                catch (Exception ex)
                {
                    MessageBox.Show($"Error: {ex.Message}");
                }
            }
        }


        private void RegisterButton_Click(object sender, RoutedEventArgs e)
        {
            RegisterWindow registerWindow = new RegisterWindow();
            registerWindow.ShowDialog();

            // If the user registered successfully, you might want to auto-login
            if (registerWindow.RegistrationSuccessful)
            {
                IsLoggedIn = true;
                this.Close();
            }
        }
    }
}