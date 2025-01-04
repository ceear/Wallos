<?php
/*
This API Endpoint accepts only GET requests.
It receives the following parameters:
- convert_currency: whether to convert to the main currency (boolean) default false.
- apiKey: the API key of the user.

It returns a downloadable VCAL file with the active subscriptions
*/

require_once '../../includes/connect_endpoint.php';

header('Content-Type: application/json, charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    // if the parameters are not set, return an error

    if (!isset($_REQUEST['api_key'])) {
        $response = [
            "success" => false,
            "title" => "Missing parameters"
        ];
        echo json_encode($response);
        exit;
    }

    $apiKey = $_REQUEST['api_key'];

    // Get user from API key
    $sql = "SELECT id, main_currency FROM user WHERE api_key = :apiKey";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':apiKey', $apiKey);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    // If the user is not found, return an error
    if (!$user) {
        $response = [
            "success" => false,
            "title" => "Invalid API key"
        ];
        echo json_encode($response);
        exit;
    }

    $userId = $user['id'];
    $userCurrencyId = $user['main_currency'];

    $sql = "SELECT subscriptions.*, currencies.symbol as currency_symbol, categories.name as category, household.name as payer_user, payment_methods.name as payment_method  FROM subscriptions
            JOIN currencies  ON subscriptions.currency_id = currencies.id AND subscriptions.user_id = currencies.user_id
            JOIN categories ON subscriptions.category_id = categories.id AND subscriptions.user_id = categories.user_id
            JOIN household ON subscriptions.payer_user_id = household.id AND subscriptions.user_id = household.user_id
            JOIN payment_methods ON subscriptions.payment_method_id = payment_methods.id AND subscriptions.user_id = payment_methods.user_id
            WHERE subscriptions.user_id = 1 AND subscriptions.inactive = false ORDER BY next_payment ASC";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscriptions.ics"');

    if ($result === false) {
        die("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:NAME:\nEND:VCALENDAR");
    }

    $icsContent = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Wallos//iCalendar//EN\nNAME:Wallos\nX-WR-CALNAME:Wallos\n";

    while ($subscription = $result->fetchArray(SQLITE3_ASSOC)) {
        $subscription['trigger'] = $subscription['notify_days_before'] ? $subscription['notify_days_before'] : 1;
        $subscription['price'] = number_format($subscription['price'], 2);

        $uid = uniqid();
        $summary = "Wallos: " . $subscription['name'];
        $description = "Price: {$subscription['currency_symbol']}{$subscription['price']}\\nCategory: {$subscription['category']}\\nPayment Method: {$subscription['payment_method']}\\nPayer: {$subscription['payer_user']}\\nNotes: {$subscription['notes']}";
        $dt_start_end = (new DateTime($subscription['next_payment']))->format('Ymd');
        $location = isset($subscription['url']) ? $subscription['url'] : '';
        $alarm_trigger = '-' . $subscription['trigger'] . 'D';

        $icsContent .= <<<ICS
        BEGIN:VEVENT
        UID:$uid
        SUMMARY:$summary
        DESCRIPTION:$description
        DTSTART:$dt_start_end
        DTEND:$dt_start_end
        LOCATION:$location
        STATUS:CONFIRMED
        TRANSP:OPAQUE
        BEGIN:VALARM
        ACTION:DISPLAY
        DESCRIPTION:Reminder
        TRIGGER:$alarm_trigger
        END:VALARM
        END:VEVENT
        
        ICS;
    }

    $icsContent .= "END:VCALENDAR\n";
    echo $icsContent;
    $db->close();
    exit;
        


} else {
    $response = [
        "success" => false,
        "title" => "Invalid request method"
    ];
    echo json_encode($response);
    exit;
}


?>