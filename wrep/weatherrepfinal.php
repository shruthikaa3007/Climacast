<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DB_HOST', 'localhost'); 
define('DB_USERNAME', 'clim'); 
define('DB_PASSWORD', ''); 
define('DB_NAME', 'weather_report'); 

// Function to create a new database connection
function createDbConnection() {
    $conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}
// Function to fetch weather data from the WeatherAPI.com API
function fetchWeatherData($place, $apiKey, $start_date, $end_date)
{
    // Convert date to timestamp for comparison
    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date);

    // Get the current timestamp
    $current_timestamp = time();

    // If the start date is in the future, use the forecast API instead
    if ($start_timestamp > $current_timestamp) {
        $url = "http://api.weatherapi.com/v1/forecast.json?key={$apiKey}&q={$place}&dt={$start_date}&end_dt={$end_date}&aqi=no";
    } else {
        $url = "http://api.weatherapi.com/v1/history.json?key={$apiKey}&q={$place}&dt={$start_date}&end_dt={$end_date}&aqi=no";
    }

    // Add &aqi=no to exclude AQI (Air Quality Index) data from the API response for more concise results.
    $ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_VERBOSE, true);
$response = curl_exec($ch);
$error = curl_error($ch);

if ($response === false) {
    echo "cURL Error: " . $error;
}
curl_close($ch);

    $data = json_decode($response, true);
    if (isset($data['forecast']['forecastday'])) {
        $weatherData = [];
        foreach ($data['forecast']['forecastday'] as $forecast) {
            $date = $forecast['date'];
            $hottest_temp = $forecast['day']['maxtemp_c'];
            $coldest_temp = $forecast['day']['mintemp_c'];
            $highest_rainfall = isset($forecast['day']['totalprecip_mm']) ? $forecast['day']['totalprecip_mm'] : 0;
            $lowest_rainfall = isset($forecast['day']['totalprecip_mm']) ? $forecast['day']['totalprecip_mm'] : 0;
            $avg_humidity = $forecast['day']['avghumidity'];
            $pressure = $forecast['day']['avgtemp_c'];
            $sunrise = $forecast['astro']['sunrise'];
            $sunset = $forecast['astro']['sunset'];

            $weatherData[] = [
                'date' => $date,
                'place' => $place,
                'hottest_temp' => $hottest_temp,
                'coldest_temp' => $coldest_temp,
                'highest_rainfall' => $highest_rainfall,
                'lowest_rainfall' => $lowest_rainfall,
                'avg_humidity' => $avg_humidity,
                'pressure' => $pressure,
                'sunrise' => $sunrise,
                'sunset' => $sunset,
            ];
        }
        return $weatherData;
    }

    return false;
}

// Function to save weather data to a new table in the database
function saveWeatherDataToNewTable($table, $data)
{
    $conn = createDbConnection();

    $createTableQuery = "CREATE TABLE IF NOT EXISTS $table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE,
        place VARCHAR(255),
        hottest_temp FLOAT,
        coldest_temp FLOAT,
        highest_rainfall FLOAT,
        lowest_rainfall FLOAT,
        avg_humidity INT,
        pressure FLOAT,
        sunrise VARCHAR(50),
        sunset VARCHAR(50)
    )";
    $conn->query($createTableQuery);

    $insertQuery = "INSERT INTO $table (date, place, hottest_temp, coldest_temp, highest_rainfall, lowest_rainfall, avg_humidity, pressure, sunrise, sunset)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insertQuery);

    foreach ($data as $weather) {
        $stmt->bind_param(
            'ssddddssss',
            $weather['date'],
            $weather['place'],
            $weather['hottest_temp'],
            $weather['coldest_temp'],
            $weather['highest_rainfall'],
            $weather['lowest_rainfall'],
            $weather['avg_humidity'],
            $weather['pressure'],
            $weather['sunrise'],
            $weather['sunset']
        );
        $stmt->execute();
    }

    if ($stmt->affected_rows > 0) {
        echo "Weather data for {$data[0]['place']} saved successfully!<br>";
    } else {
        echo "Error saving weather data for {$data[0]['place']}!<br>";
    }

    $stmt->close();
    $conn->close();
}

// Function to generate PDF report and save it locally
function generateWeatherReport($start_date, $end_date, $place = null,$table)
{
    $conn = createDbConnection();

    $query = "SELECT * FROM $table";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        die("Query preparation failed: " . $conn->error);
    }

    

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return "No weather data available for the specified date range and place.";
    }

    // Include the TCPDF library
    require_once('tcpdf/tcpdf.php');

    // Generate the PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Weather Report');
    $pdf->SetTitle('Weather Report - ' . $start_date . ' to ' . $end_date);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->AddPage();

    $content = "<h1>Weather Report - {$start_date} to {$end_date}</h1><br>";

    // Initialize variables to track highest temperature and rainfall
    $highest_temp = PHP_INT_MIN;
    $highest_rainfall = 0;
    $content .= "<strong>{$place}:</strong><br>";

    while ($row = $result->fetch_assoc()) {
        $place = $row['place'];
        $hottest_temp = $row['hottest_temp'];
        $highest_temp = max($highest_temp, $hottest_temp); // Update highest temperature
        $coldest_temp = $row['coldest_temp'];
        $highest_rainfall = max($highest_rainfall, $row['highest_rainfall']); // Update highest rainfall
        $lowest_rainfall = $row['lowest_rainfall'];
        $avg_humidity = $row['avg_humidity'];
        $pressure = $row['pressure'];
        $sunrise = $row['sunrise'];
        $sunset = $row['sunset'];
        $date=$row['date'];

        $content .= "<strong>{$date}:</strong><br>";
        $content .= "Hottest Temp: {$hottest_temp}°C<br>";
        $content .= "Coldest Temp: {$coldest_temp}°C<br>";
        $content .= "Highest Rainfall: {$row['highest_rainfall']}mm<br>";
        $content .= "Lowest Rainfall: {$lowest_rainfall}mm<br>";
        $content .= "Average Humidity: {$avg_humidity}%<br>";
        $content .= "Pressure: {$pressure} hPa<br>";
        $content .= "Sunrise: {$sunrise}<br>";
        $content .= "Sunset: {$sunset}<br><br>";
    }

    $content .= "<strong>Highest Temperature Recorded: {$highest_temp}°C</strong><br>";
    $content .= "<strong>Highest Rainfall Recorded: {$highest_rainfall}mm</strong><br>";

    $pdf->writeHTML($content);
    $pdf_filename = "weather_report_{$start_date}_to_{$end_date}";
    if ($place) {
        $pdf_filename .= "_{$place}";
    }
    $pdf_filename .= ".pdf";

    // Save the PDF locally in the 'output' folder within your project directory
    $pdf->Output(__DIR__ . "/output/{$pdf_filename}", 'F');

    $stmt->close();
    $conn->close();

    return "output/{$pdf_filename}";
}

// Example usage
$apiKey = '3a1b3c07d25248a98e671433230508'; // Replace 'YOUR_WEATHERAPI_COM_API_KEY' with your actual WeatherAPI.com API key

// Fetch weather data for a specific period and place (e.g., 'New York', 'London') and store it in the database
$start_date =$_POST['date_from'] ; // Replace with the start date provided by the user
$end_date = $_POST['date_to'];   // Replace with the end date provided by the user
$place =$_POST['place'] ; 
$weatherData = fetchWeatherData($place, $apiKey, $start_date, $end_date);

if ($weatherData) {
    // Generate a unique table name for each place and period
    $table = "weather_data_" . strtolower(str_replace([' ', '-'], '_', "{$place}_{$start_date}_{$end_date}"));
    saveWeatherDataToNewTable($table, $weatherData);

    // Generate and save the weather report as a PDF for the specified date range and place
    $reportFilename = generateWeatherReport($start_date, $end_date, $place,$table);
    echo "Weather report for {$start_date} to {$end_date} in {$place} saved as {$reportFilename}";
} else {
    echo "Error fetching weather data for {$place}";
}
?>