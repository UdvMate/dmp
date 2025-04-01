using MySql.Data.MySqlClient;
using System.Collections.ObjectModel;
using System.Security.Cryptography;
using System.Text;

namespace dmpApp.Data
{
    public class DatabaseService
    {
        private const string ConnectionString = "server=your_server;database=dmproject;user=your_user;password=your_password;";

        public async Task<User?> AuthenticateUser(string username, string password)
        {
            using var connection = new MySqlConnection(ConnectionString);
            await connection.OpenAsync();

            string hashedPassword = HashPassword(password);
            string query = "SELECT * FROM users WHERE username = @username AND password = @password";
            using var command = new MySqlCommand(query, connection);
            command.Parameters.AddWithValue("@username", username);
            command.Parameters.AddWithValue("@password", hashedPassword);

            using var reader = await command.ExecuteReaderAsync();
            if (await reader.ReadAsync())
            {
                return new User
                {
                    Id = reader.GetInt32("id"),
                    Username = reader.GetString("username"),
                    Email = reader.GetString("email"),
                    Role = reader.GetString("role")
                };
            }
            return null;
        }

        public async Task<ObservableCollection<Flashcard>> GetFlashcardsAsync(int folderId)
        {
            var flashcards = new ObservableCollection<Flashcard>();
            using var connection = new MySqlConnection(ConnectionString);
            await connection.OpenAsync();

            string query = "SELECT * FROM flashcards WHERE folder_id = @folderId";
            using var command = new MySqlCommand(query, connection);
            command.Parameters.AddWithValue("@folderId", folderId);

            using var reader = await command.ExecuteReaderAsync();
            while (await reader.ReadAsync())
            {
                flashcards.Add(new Flashcard
                {
                    Id = reader.GetInt32("id"),
                    Question = reader.GetString("question"),
                    Answer = reader.GetString("answer"),
                    FolderId = reader.GetInt32("folder_id")
                });
            }
            return flashcards;
        }

        private string HashPassword(string password)
        {
            using var sha256 = SHA256.Create();
            byte[] hashBytes = sha256.ComputeHash(Encoding.UTF8.GetBytes(password));
            return Convert.ToBase64String(hashBytes);
        }
    }

    public class User
    {
        public int Id { get; set; }
        public string Username { get; set; }
        public string Email { get; set; }
        public string Role { get; set; }
    }

    public class Flashcard
    {
        public int Id { get; set; }
        public string Question { get; set; }
        public string Answer { get; set; }
        public int FolderId { get; set; }
    }
}
