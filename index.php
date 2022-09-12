<!DOCTYPE html>

<?php
	require_once(__DIR__ . "/vendor/autoload.php");

	// Download an addon using its UUID.
	$download_id = $_GET["download"] ?? "";

	if (!empty($download_id))
	{
		$download_product = new \Everyday\GmodStore\Sdk\Api\ProductVersionsApi($client, $config);

		try
		{
			$result = $download_product->listProductVersions($download_id, 1);
			$result = json_decode($result[0], true);
			$result = $result["data"][0];

			$version_id = $result["id"];
		}
		catch (Exception $error)
		{
			$output .= $error->getMessage() . "<br />" . PHP_EOL;
		}

		try
		{
			// Récupération d'un lien de téléchargement unique.
			//	Note : une modification dans code source est a réaliser pour que la fonction
			//		retourne un résultat correcte, ligne 1005 de /vendor/everyday/gmodstore-sdk/lib/Api/ProductVersionsApi.php,
			//		il est modifier de modifier la ligne par « json_decode($content, true) »,
			//		la bibliothèque a été conçue pour la version 2 de l'API et mon script utilise
			//		la dernière et troisième version d'où cette modification nécessaire.
			$result = $download_product->getProductDownloadToken($download_id, $version_id);

			header("Pragma: no-cache");
			header("Expires: 0");
			header("Location: " . $result["url"]);
			header("Cache-Control: no-store, no-cache, must-revalidate");
			header("Cache-Control: post-check=0, pre-check=0", false);

			exit();
		}
		catch (Exception $error)
		{
			$error .= $error->getMessage();
		}
	}

	// Display all purchased addons.
	$token = $_GET["token"] ?? "";

	if (!empty($token))
	{
		$config = \Everyday\GmodStore\Sdk\Configuration::getDefaultConfiguration()->setAccessToken($token);
		$client = new \GuzzleHttp\Client();

		// Login to user account.
		$user_id = "";
		$user_data = new \Everyday\GmodStore\Sdk\Api\UsersApi($client, $config);

		try
		{
			$result = $user_data->getMe();
			$result = $result["data"]["user"];

			$user_id = $result["id"];
			$account = $result["name"] . " (" . $result["steamId"] . ") [" . $user_id . "]";
		}
		catch (Exception $error)
		{
			$output .= $error->getMessage() . "<br />" . PHP_EOL;
		}

		// Retrieving purchased scripts.
		$user_purchases = new \Everyday\GmodStore\Sdk\Api\UserProductPurchasesApi($client, $config);
		$product_identifiers = [];

		function getProductIdentifiers(string $id, string $cursor = null)
		{
			global $user_purchases;
			$result = $user_purchases->listUserPurchases($id, $cursor);
			$result = json_decode($result[0], true);

			global $product_identifiers;
			$product_identifiers = array_merge($product_identifiers, array_column($result["data"], "productId"));

			// Checking all pages returned by the API.
			$cursor = $result["cursors"]["next"];

			if (!empty($cursor))
			{
				getProductIdentifiers($id, $cursor);
			}
		}

		try
		{
			getProductIdentifiers($user_id);

			$product_identifiers["ids[]"] = $product_identifiers;
		}
		catch (Exception $error)
		{
			$output .= $error->getMessage() . "<br />" . PHP_EOL;
		}

		// Retrieving data from previously collected scripts.
		$product_informations = new \Everyday\GmodStore\Sdk\Api\ProductsApi($client, $config);

		try {
			$result = $product_informations->getProducts($product_identifiers);
			$result = $result["data"];

			$total = 0;
			$addons = "";

			foreach ($result as $value)
			{
				// Building the HTML structure.
				$addons .= '
					<li>
						<b>' . $value["name"] . '</b>
						<br />
						<a href="' . $_SERVER["PHP_SELF"] . '?token=' . $token . '&download=' . $value["id"] . '">Download</a>
						—
						<a href="https://www.gmodstore.com/market/view/' . $value["id"] . '" target="_blank">Store</a>
					</li>'
				;

				// Calculating the price of all addons.
				$currency = $value["price"]["original"]["currency"];

				if ($value["price"]["raw"] !== 99999)
				{
					$total += intval($value["price"]["original"]["amount"]);
				}
			}

			// Displaying the total money spent.
			$money = number_format($total / 100, 2, ",", " ") . " " . $currency;
		}
		catch (Exception $error)
		{
			$output .= $error->getMessage() . "<br />" . PHP_EOL;
		}
	}
?>

<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />

		<title>GmodStore Downloader</title>

		<style>
			input[type = text]
			{
				width: calc(100% - 0.5rem);
				display: block;
				max-width: 30rem;
				margin-bottom: 1rem;
			}
		</style>
	</head>
	<body>
		<!-- Title -->
		<h1>📥 GmodStore Downloader</h1>

		<!-- Account details -->
		<?php if (!empty($account)):  ?>
			<h2>🔐 <?= $account ?></h2>
		<?php endif; ?>

		<?php if (!empty($addons)):  ?>
			<!-- Addons list -->
			<ul>
				<?= $addons ?>
			</ul>

			<!-- Money spent -->
			<h3>💰 <?= $money ?></h3>
		<?php else: ?>
			<!-- Authentication form -->
			<p>Test</p>

			<form method="GET">
				<label for="token">Account authentication token :</label>
				<input type="text" autoComplete="off" spellCheck="false" id="token" name="token" required />

				<input type="submit" value="Connect" />
			</form>
		<?php endif; ?>

		<!-- Error output -->
		<?php if (!empty($output)):  ?>
			<h3>⚠️ Error output ⚠️</h3>

			<p><?= $output ?></p>
		<?php endif; ?>
	</body>
</html>