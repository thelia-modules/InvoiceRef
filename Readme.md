# Invoice Number module

This module allows you to manage the invoice number for your orders.

## Installation

* Copy the module into ```<thelia_root>/local/modules/``` directory and be sure that the name of the module is InvoiceRef.
* Activate it in your thelia administration panel

## Usage

Once installed, you have to set the invoice number used by Thelia. After that, this number will be increased after each payment. For example if you start with number 1000, this number will be increased by 1 after each payment so you will have 1000, after 1001, after 1002, etc.

If you don't set this number, it starts right after the highest numeric invoice number already given to an order, or at 1.
Prefixed invoice numbers (`FA-0099`) are left out of that computation, as in 3.0.0: set the counter yourself to
continue a prefixed series.

The counter must end with a number, or contain only letters and digits: the configuration page refuses any other
value.

A number ending with digits keeps its prefix and its width (`FA-0099`, then `FA-0100`).

### Numbered statuses

By default, an order gets its invoice number when it reaches the paid status (or a custom status declared as
equivalent to paid). The configuration page lets you choose other statuses as well, for instance refunded: an order
that reaches any of them gets a number if it has none yet. An order never gets a second number.

The list is stored in the `invoiceRefStatuses` configuration variable, as comma-separated order status codes.

### Numbering guarantees

* Each number is drawn under a lock on the `invoiceRef` configuration row, in the database: two orders paid at the
  same time, on the same server or not, never get the same number.
* A number already carried by an order is skipped.
* A payment notification received twice for the same order does not consume a second number.
* The status change is never cancelled by the numbering, even when its caller wraps it in a transaction: the
  counter is checked before anything is written, and the numbering runs under a savepoint.
* If the order cannot be numbered (no counter configured, for instance), the status change goes on without a number
  and the error is logged (`InvoiceRef: failed to give order #<id> an invoice number: <cause>`). Nothing numbers the
  order later on its own: once the cause is fixed, run `php Thelia invoiceref:assign <order id or reference>`.
* An order numbered on a status other than paid gets today's invoice date if it had none.

A module sharing the series (credit notes numbered like invoices, for instance) draws its numbers with
`InvoiceRef\Service\InvoiceRefSequence::next()`, inside a transaction, to be serialized with the orders.

Activating the module turns the core numbering off (`invoice_ref_auto` set to 0): two numberings on the same orders
would run two series. The configuration page warns if it is turned back on.

## Tests

Integration tests, run from the root of the Thelia project the module is installed in, against a test database of
their own (its name must end with `_test`, the suite refuses any other):

```bash
DATABASE_NAME=invoiceref_test php bin/test-prepare
DATABASE_NAME=invoiceref_test vendor/bin/phpunit --bootstrap vendor/thelia/modules/InvoiceRef/Tests/bootstrap.php vendor/thelia/modules/InvoiceRef/Tests
```

The suite keeps its compiled container in `var/cache/invoiceref-tests/`. The Propel configuration of the test
environment (`var/propel/test/`) holds the database it was generated for: clear it when switching databases.
`ConcurrentNumberingTest` starts two PHP processes and commits its data.
