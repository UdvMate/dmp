using MySql.Data.MySqlClient;
using System;
using System.Collections.Generic;
using System.Threading.Tasks;

namespace DMappFinal
{
    public class DatabaseService
    {
        private const string ConnectionString = "Server=127.0.0.1;Database=dmproject;User Id=root;Password=;";

        public async Task<List<string>> GetFlashcardsAsync()
        {
            var flashcards = new List<string>();

            using var connection = new MySqlConnection(ConnectionString);
            await connection.OpenAsync();

            string query = "SELECT question FROM flashcards";
            using var command = new MySqlCommand(query, connection);
            using var reader = await command.ExecuteReaderAsync();

            while (await reader.ReadAsync())
            {
                flashcards.Add(reader.GetString(0));
            }

            return flashcards;
        }
    }
}
