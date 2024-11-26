<?php
	// Download an addon using its UUID.
	$token = htmlspecialchars($_GET["token"] ?? "", ENT_QUOTES, "UTF-8");
	$downloadId = htmlspecialchars($_GET["download"] ?? "", ENT_QUOTES, "UTF-8");

	if (!empty($downloadId))
	{
		$versionId = "";
		$downloadUrl = "";

		// Get the latest version of the product.
		function getLatestProductVersion(): void
		{
			global $token, $output, $versionId, $downloadId;

			$productVersion = file_get_contents("https://api.pivity.com/v3/products/$downloadId/versions", context: stream_context_create([
				"http" => [
					"header" => "Authorization: Bearer " . $token . "\r\nX-Tenant: gmodstore.com\r\n",
					"ignore_errors" => true
				]
			]));

			$productVersion = json_decode($productVersion, true);

			if (empty($productVersion["data"]))
			{
				$output .= $productVersion["message"] . "<br />" . PHP_EOL;
			}
			else
			{
				$versionId = $productVersion["data"][0]["id"];
			}
		}

		// Generate a download token for the product.
		function getProductDownloadToken(): void
		{
			global $token, $output, $downloadId, $versionId, $downloadUrl;

			$productDownload = file_get_contents("https://api.pivity.com/v3/products/$downloadId/versions/$versionId/download", context: stream_context_create([
				"http" => [
					"method" => "POST",
					"header" => "Authorization: Bearer " . $token . "\r\nX-Tenant: gmodstore.com\r\n",
					"ignore_errors" => true
				]
			]));

			$productDownload = json_decode($productDownload, true);

			if (empty($productDownload["data"]))
			{
				$output .= $productDownload["message"] . "<br />" . PHP_EOL;
			}
			else
			{
				$downloadUrl = $productDownload["data"]["url"];
			}
		}

		getLatestProductVersion();
		getProductDownloadToken();

		if (!empty($versionId) && !empty($downloadUrl) && filter_var($downloadUrl, FILTER_VALIDATE_URL))
		{
			header("Pragma: no-cache");
			header("Expires: 0");
			header("Location: " . $downloadUrl);
			header("Cache-Control: no-store, no-cache, must-revalidate");
			header("Cache-Control: post-check=0, pre-check=0", false);

			exit();
		}
	}

	// Display all purchased addons.
	if (!empty($token))
	{
		// Login to user account.
		$output = "";
		$userId = "";
		$userData = file_get_contents("https://api.pivity.com/v3/me", context: stream_context_create([
			"http" => [
				"header" => "Authorization: Bearer " . $token . "\r\nX-Tenant: gmodstore.com\r\n",
				"ignore_errors" => true
			]
		]));

		$userData = json_decode($userData, true);

		if (empty($userData["data"]["user"]))
		{
			// Authentication failed.
			$output .= $userData["message"] . "<br />" . PHP_EOL;
		}
		else
		{
			// Authentication successful.
			$userData = $userData["data"]["user"];

			$userId = $userData["id"];
			$account = $userData["name"] . " (" . $userData["steamId"] . ") [" . $userId . "]";
		}

		// Retrieving purchased scripts.
		$productIdentifiers = [];

		function getProductIdentifiers(?string $cursor): void
		{
			// Make a first API call to get the first page of purchases.
			global $userId, $token, $output, $productIdentifiers;

			$userPurchases = file_get_contents("https://api.pivity.com/v3/users/$userId/purchases?perPage=100&cursor=$cursor", context: stream_context_create([
				"http" => [
					"header" => "Authorization: Bearer " . $token . "\r\nX-Tenant: gmodstore.com\r\n",
					"ignore_errors" => true
				]
			]));

			$userPurchases = json_decode($userPurchases, true);

			if (empty($userPurchases["data"]))
			{
				$output .= $userPurchases["message"] . "<br />" . PHP_EOL;
			}
			else
			{
				$productIdentifiers = array_merge($productIdentifiers, array_column($userPurchases["data"], "productId"));
			}

			// Checking all pages given by the API.
			$cursor = $userPurchases["cursors"]["next"];

			if (!empty($cursor))
			{
				getProductIdentifiers($cursor);
			}
		}

		getProductIdentifiers(null);

		// Retrieving data from previously collected scripts.
		$products = [];

		function getProductDetails(): void
		{
			global $token, $output, $products, $productIdentifiers;

			$parameters = http_build_query(["ids" => $productIdentifiers]);
			$productDetails = file_get_contents("https://api.pivity.com/v3/products/batch?$parameters", context: stream_context_create([
				"http" => [
					"header" => "Authorization: Bearer " . $token . "\r\nX-Tenant: gmodstore.com\r\n",
					"ignore_errors" => true
				]
			]));

			$productDetails = json_decode($productDetails, true);

			foreach ($productDetails["data"] as $product) {
				// Debugging output
				$output .= "<pre>" . print_r($product, true) . "</pre>";
			}
			

			if (empty($productDetails["data"])) {
				$output .= $productDetails["message"] . "<br />" . PHP_EOL;
			} else {
				foreach ($productDetails["data"] as $product) {
					// Safely handle optional fields
					$thumbnail = $product["images"]["listingSmall"] ?? 'https://via.placeholder.com/460x130?text=No+Image';
					$priceData = $product["price"]["original"] ?? null;
					$priceAmount = $priceData["amount"] ?? "0";
					$priceCurrency = $priceData["currency"] ?? "USD";

					$products[] = [
						"id" => $product["id"],
						"name" => $product["name"] ?? "Unnamed Product",
						"shortDescription" => $product["shortDescription"] ?? "No description available.",
						"price" => [
							"amount" => intval($priceAmount),
							"currency" => $priceCurrency,
							"formatted" => $priceData["formatted"] ?? "Free",
						],
						"thumbnail" => $thumbnail,
					];
				}
			}

			// Handle pagination (if there are more than 100 identifiers)
			if (count($productIdentifiers) > 100) {
				$productIdentifiers = array_slice($productIdentifiers, 100);
				getProductDetails();
			}
		}


		getProductDetails();

		// Calculating the total amount spent.
		$total = 0;
		$addons = "";

		foreach ($products as $product) {
			// Safely retrieve price details
			$price = $product["price"];
			$priceAmount = $price["amount"] ?? 0;
			$priceCurrency = $price["currency"] ?? "USD";

			// Add to total price
			if ($priceAmount !== 99999) { // Assuming 99999 represents a free product
				$total += $priceAmount;
			}

			// Build the HTML structure
			$addons .= '
				<li class="bg-white rounded-lg shadow-lg p-4">
					<img src="' . htmlspecialchars($product["thumbnail"], ENT_QUOTES) . '" alt="' . htmlspecialchars($product["name"], ENT_QUOTES) . '" class="w-full h-40 object-cover rounded-md mb-4" />
					<h3 class="text-lg font-semibold mb-2">' . htmlspecialchars($product["name"], ENT_QUOTES) . '</h3>
					<p class="text-gray-600 text-sm mb-4">' . htmlspecialchars($product["shortDescription"], ENT_QUOTES) . '</p>
					<div class="flex space-x-4">
						<a href="?token=' . $token . '&download=' . $product["id"] . '" class="px-4 py-2 bg-blue-500 text-white rounded-lg shadow-md hover:bg-blue-600">Download</a>
						<a href="https://www.gmodstore.com/market/view/' . $product["id"] . '" target="_blank" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg shadow-md hover:bg-gray-300">View in Store</a>
					</div>
				</li>';
		}

		// Format the total money spent
		$money = number_format($total / 100, 2, ",", " ") . " " . htmlspecialchars($priceCurrency, ENT_QUOTES);

	}
?>



<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="description" content="A simple web page to download addons through the GmodStore API." />
        <title>GmodStore Downloader</title>

        <!-- Tailwind CSS -->
        <script src="https://cdn.tailwindcss.com"></script>

        <!-- Favicon -->
        <link rel="icon" type="image/webp" href="assets/favicons/48x48.webp" />
    </head>
    <body class="bg-gray-100 text-gray-800 font-sans">
        

        <!-- Main Content -->
        <div class="container mx-auto p-6">
            <!-- Title -->
            <h1 class="text-3xl font-bold text-center mb-8">
                <a href="https://github.com/FlorianLeChat/GmodStore-Downloader" target="_blank" class="text-blue-500 hover:underline">📥 GmodStore Downloader</a>
            </h1>

			<!-- Account Details -->
			<?php if (!empty($account)): ?>
				<div class="mb-6">
					<h2 class="text-xl text-gray-700">
						🔐 Logged in as:
						<span class="relative group">
							<a 
								href="https://www.gmodstore.com/users/<?= urlencode($userData['name']) ?>" 
								target="_blank" 
								class="capitalize text-blue-500 hover:underline">
								<?= htmlspecialchars($userData['name'], ENT_QUOTES) ?>
							</a>
						</span>
					</h2>
				</div>
			<?php endif; ?>



            <?php if (!empty($addons)): ?>
				<!-- Addons List -->
				<ul class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<?php foreach ($products as $product): ?>
						<li class="bg-white rounded-lg shadow-lg p-4">
							<!-- Product Thumbnail -->
							<div class="relative w-full h-40">
							<img 
								src="<?= $product['thumbnail'] ?>" 
								alt="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>" 
								class="w-full h-full object-contain rounded-md"
							/>
							</div>
							<!-- Product Name -->
							<h3 class="text-lg font-semibold mb-2"><?= htmlspecialchars($product['name'], ENT_QUOTES) ?></h3>

							<!-- Links -->
							<div class="flex space-x-4">
								<a 
									href="?token=<?= $token ?>&download=<?= $product['id'] ?>" 
									class="px-4 py-2 bg-blue-500 text-white rounded-lg shadow-md hover:bg-blue-600">
									Download
								</a>
								<a 
									href="https://www.gmodstore.com/market/view/<?= $product['id'] ?>" 
									target="_blank" 
									class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg shadow-md hover:bg-gray-300">
									View in Store
								</a>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>


                <!-- Money Spent -->
                <h3 class="text-lg font-bold mt-8">💰 Total Spent: <?= $money ?></h3>
            <?php else: ?>
               <!-- Authentication Form -->
				<div class="bg-white rounded-lg shadow-lg p-6">
					<p class="mb-4">
						A token can be generated at the following address (<strong>account login required</strong>):
						<a href="https://www.gmodstore.com/settings/personal-access-tokens" target="_blank" class="text-blue-500 hover:underline">Personal Access Tokens</a>
					</p>
					<p class="mb-4">
						Ensure the token includes the following permissions:
					</p>
					<ul class="list-disc list-inside bg-gray-100 p-4 rounded-md mb-4">
						<li><code class="bg-gray-200 text-gray-800 px-1 rounded">products:read</code></li>
						<li><code class="bg-gray-200 text-gray-800 px-1 rounded">product-versions:read</code></li>
						<li><code class="bg-gray-200 text-gray-800 px-1 rounded">product-versions:download</code></li>
						<li><code class="bg-gray-200 text-gray-800 px-1 rounded">users:read</code></li>
						<li><code class="bg-gray-200 text-gray-800 px-1 rounded">user-purchases:read</code></li>
					</ul>
					<form method="GET" class="space-y-4">
						<label for="token" class="block text-gray-700">Account authentication token:</label>
						<input type="text" id="token" name="token" required class="w-full border rounded-lg p-2">
						<button type="submit" class="w-full bg-blue-500 text-white rounded-lg py-2 hover:bg-blue-600">
							Connect
						</button>
					</form>
				</div>

            <?php endif; ?>

        </div>
    </body>
</html>
