protected override void OnAppearing()
{
    base.OnAppearing();

    var databaseService = new DatabaseService();
    var flashcards = databaseService.GetFlashcards();
    FlashcardsCollectionView.ItemsSource = flashcards;
}
