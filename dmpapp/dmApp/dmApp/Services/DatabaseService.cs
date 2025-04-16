using MySql.Data.MySqlClient;

public class DatabaseService
{
    private readonly string _connectionString = "Server=127.0.0.1;Database=dmproject;User Id=root;Password=yourpassword;";

    public List<Flashcard> GetFlashcards()
    {
        var flashcards = new List<Flashcard>();
        using (var connection = new MySqlConnection(_connectionString))
        {
            connection.Open();
            var command = new MySqlCommand("SELECT * FROM flashcards", connection);
            using (var reader = command.ExecuteReader())
            {
                while (reader.Read())
                {
                    flashcards.Add(new Flashcard
                    {
                        Id = reader.GetInt32("id"),
                        Question = reader.GetString("question"),
                        Answer = reader.GetString("answer")
                    });
                }
            }
        }
        return flashcards;
    }

    public void AddUser(User user)
    {
        using (var connection = new MySqlConnection(_connectionString))
        {
            connection.Open();
            var command = new MySqlCommand("INSERT INTO users (email, password) VALUES (@Email, @Password)", connection);
            command.Parameters.AddWithValue("@Email", user.Email);
            command.Parameters.AddWithValue("@Password", user.Password);
            command.ExecuteNonQuery();
        }
    }
}
