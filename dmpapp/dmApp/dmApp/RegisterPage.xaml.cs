using MySql.Data.MySqlClient;

private async void OnRegisterClicked(object sender, EventArgs e)
{
    using (var connection = new MySqlConnection(_connectionString))
    {
        connection.Open();
        var command = new MySqlCommand("INSERT INTO users (email, password) VALUES (@Email, @Password)", connection);
        command.Parameters.AddWithValue("@Email", emailEntry.Text);
        command.Parameters.AddWithValue("@Password", passwordEntry.Text);
        command.ExecuteNonQuery();

        await DisplayAlert("Success", "Registration completed", "OK");
        await Navigation.PushAsync(new LoginPage());
    }
}
