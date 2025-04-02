using MySql.Data.MySqlClient;

private async void OnLoginClicked(object sender, EventArgs e)
{
    using (var connection = new MySqlConnection(_connectionString))
    {
        connection.Open();
        var command = new MySqlCommand("SELECT * FROM users WHERE email = @Email AND password = @Password", connection);
        command.Parameters.AddWithValue("@Email", emailEntry.Text);
        command.Parameters.AddWithValue("@Password", passwordEntry.Text);

        using (var reader = command.ExecuteReader())
        {
            if (reader.Read())
            {
                await Navigation.PushAsync(new MainPage());
            }
            else
            {
                await DisplayAlert("Error", "Invalid credentials", "OK");
            }
        }
    }
}
