===========================================================================
 TWITTPAY - FOSSBilling payment gateway
===========================================================================

 WHERE IT GOES
   Extract this zip at your FOSSBilling root - the folder that has library/ and
   index.php in it. Two files land in place:

     library/Payment/Adapter/TwittPay.php          the adapter
     library/Payment/Adapter/twittpay/logo.png     the logo

   Keep the file name exactly as it is. FOSSBilling finds an adapter by matching
   the file name to the class name, and Linux servers are case sensitive.

 INSTALL
   1. Admin area -> System -> Payment Gateways.
   2. Find "TwittPay" in the list of available gateways and click the plus to
      install it.
   3. Fill in:


        Brand Key           from your gateway dashboard, under Brands

        USD to BDT Rate   only used when the invoice is not already in BDT

   4. Tick "Enabled", save, and pay a test invoice.

 HOW IT WORKS
   * The invoice page shows a Pay Now button. The payment is created the moment
     that page is rendered, so the button just carries the customer over.
   * The gateway calls FOSSBilling's IPN URL from its own server. That is where
     the invoice is actually settled.
   * The IPN handler verifies the transaction against the API first. A hand-typed
     status does nothing at all.
   * COMPLETED credits the client and pays the invoice.
   * PENDING is logged and nothing is paid yet - the customer has sent the money
     and your merchant has not approved it. The gateway calls again with the
     answer, and that call settles the invoice. Do not ask the customer to pay
     twice.

 CURRENCY
   The gateway charges BDT.

   * A BDT invoice is sent as it is.
   * Any other currency is multiplied by the USD to BDT Rate, and the invoice's
     own amount and currency ride along in metadata - so the transaction and the
     client credit FOSSBilling records stay in the invoice's currency.

 WHAT TO WATCH
   * Turn on "auto redirect" in the gateway settings if you want the customer
     taken straight to the payment page with no button click.
   * Refunds are not done through the API. Refund on the gateway side, then
     record it in FOSSBilling by hand.
   * Subscriptions are not supported - one-off invoice payments only.

 A NOTE ON THE ORIGINAL PACKAGING
   The PipraPay version of this module shipped the adapter inside a subfolder
   (Adapter/piprapay/piprapay.php). FOSSBilling does not scan subfolders for
   adapters, so this port puts the class file directly in Adapter/ and keeps only
   the logo in the subfolder.

 CHECKED
   The PHP was checked with a lexer that balances braces only inside real PHP
   code. PHP itself was NOT run - there is no PHP binary on the machine this was
   built on, so php -l was never executed. Test it on a staging install first.
