<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ProductBatch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Path to your CSV file
        $filePath = database_path('seeds/products.csv');  // Update this path if necessary

        // Open the file for reading
        if (($handle = fopen($filePath, 'r')) !== false) {
            // Read the header row to get the column names
            $headers = fgetcsv($handle);

            DB::beginTransaction();
            try {
                // Loop through each row in the CSV file
                while (($row = fgetcsv($handle)) !== false) {
                    // Map the row to an associative array using headers
                    $data = array_combine($headers, $row);

                    // Step 1: Insert into the `products` table
                    // Insert the product and get the product_id
                    $product = Product::create([
                        'barcode' => $data['barcode'],        // barcode
                        'name' => $data['name'],              // name
                        'alert_quantity' => (int) $data['alert_quantity'], // alert_quantity
                        'unit' => 'PC',
                    ]);

                    $contact = Contact::where('name', $data['supplier'])->first();
                    $contactId = $contact ? $contact->id : null;

                    // Step 2: Insert into the `product_batches` table
                    // Insert the batch and get the batch_id
                    $batch = ProductBatch::create([
                        'product_id' => $product->id,           // product_id from the products table
                        'batch_number' => $data['batch_number'],  // batch_number
                        'cost' => (float) str_replace(',', '', $data['cost']),              // cost
                        'price' => (float) str_replace(',', '', $data['price']),            // price
                        'contact_id' => $contactId,
                        'created_by' => 1,                    // created_by
                        'is_featured' => 1,                   // Make products featured by default for POS
                    ]);

                    // Step 3: Insert into the `product_stocks` table
                    // Insert the stock data using product_id and batch_id
                    ProductStock::create([
                        'store_id' => 1,                      // store_id (1 in your case)
                        'product_id' => $product->id,         // product_id (from products table)
                        'batch_id' => $batch->id,             // batch_id (from product_batches table)
                        'quantity' => (int) $data['quantity'],      // quantity
                        'created_by' => 1,                    // created_by
                    ]);
                }

                // Commit the transaction if everything was successful
                DB::commit();
                $this->command->info('Products, product_batches, and product_stocks tables seeded from CSV!');
            } catch (\Exception $e) {
                // Rollback the transaction if an error occurs
                DB::rollBack();
                $this->command->error('Error while seeding: ' . $e->getMessage());
            }

            // Close the file after processing
            fclose($handle);
        }

        $this->command->info('Products, product_batches, and product_stocks tables seeded from CSV!');
    }
}
