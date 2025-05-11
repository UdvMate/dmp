using MySql.Data.MySqlClient;
using System;
using System.Windows;

namespace dmp
{
    public partial class AdminWindow : Window
    {
        private string connectionString = "Server=localhost;Database=dmproject;Uid=root;Pwd=;";

        public AdminWindow()
        {
            InitializeComponent();
            LoadUsers();
        }

        // Felhasználók betöltése
        private void LoadUsers()
        {
            try
            {
                using (var conn = new MySqlConnection(connectionString))
                {
                    conn.Open();
                    string query = "SELECT user_id, username, email FROM users WHERE username != 'admin'";
                    using (var cmd = new MySqlCommand(query, conn))
                    using (var reader = cmd.ExecuteReader())
                    {
                        UserListBox.Items.Clear();
                        while (reader.Read())
                        {
                            var user = new UserInfo
                            {
                                UserId = reader.GetInt32("user_id"),
                                Username = reader.GetString("username"),
                                Email = reader.GetString("email")
                            };
                            UserListBox.Items.Add(user);
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                MessageBox.Show("Error loading users: " + ex.Message);
            }
        }

        // Felhasználó törlése
        private void DeleteUserButton_Click(object sender, RoutedEventArgs e)
        {
            if (UserListBox.SelectedItem is UserInfo user)
            {
                var result = MessageBox.Show($"Are you sure you want to delete {user.Username}?", "Delete User", MessageBoxButton.YesNo);
                if (result == MessageBoxResult.Yes)
                {
                    try
                    {
                        using (var conn = new MySqlConnection(connectionString))
                        {
                            conn.Open();
                            string query = "DELETE FROM users WHERE user_id = @UserId";
                            using (var cmd = new MySqlCommand(query, conn))
                            {
                                cmd.Parameters.AddWithValue("@UserId", user.UserId);
                                cmd.ExecuteNonQuery();
                                LoadUsers(); // Refresh user list
                            }
                        }
                    }
                    catch (Exception ex)
                    {
                        MessageBox.Show("Error deleting user: " + ex.Message);
                    }
                }
            }
        }

        // Felhasználók listájának frissítése
        private void RefreshButton_Click(object sender, RoutedEventArgs e)
        {
            LoadUsers();
        }
    }

    public class UserInfo
    {
        public int UserId { get; set; }
        public string Username { get; set; }
        public string Email { get; set; }
    }
}
