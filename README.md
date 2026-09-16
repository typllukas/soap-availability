# soap-availability

[![check](https://github.com/typllukas/soap-availability/actions/workflows/check.yml/badge.svg)](https://github.com/typllukas/soap-availability/actions/workflows/check.yml)

Stock availability and prices over SOAP, for partner integrations. Learning project for SOAP in PHP,
not a product. Symfony 7.4 on PHP 8.5, `ext-soap` on both sides, no SOAP library.

The WSDL is written first and by hand, the PHP maps to it. Contract first, document/literal wrapped.

## What it is not

- Not a product. Nothing is persisted, and nothing is authenticated. In SOAP that would go in a
  `soap:header` or in the transport, not in the operation body.
- Not generated. No `AutoDiscover` on the server, no generated client, the WSDL is the source.
- Not a front end. There is a console command that calls the service, that is all.

## Operations

One address, two operations. SOAP 1.1 binds to HTTP with POST, so a read is a POST as well and the
operation is named by the element inside the envelope.

```
POST /stock-availability

getStockAvailability(partnerId, productCode[]) -> product[]
createOrder(partnerId, item[]) -> orderNumber, totalPrice, currency
fault StockAvailabilityFault{code, productCode?}
```

The fault is declared in the WSDL like a response, so an error comes back as a typed element in the
body, under HTTP 500. A message that does not match the schema comes back as `INVALID_REQUEST`.

`GET /stock-availability?wsdl` serves the contract, with `soap:address` set to the host it was
fetched from. That one is a convention every stack follows, it is not part of SOAP.

Two roles, provider and consumer. `SoapClient` runs against our own contract and against an external
one.

## Running it

Everything runs in Docker, nothing on the host. You need Docker with the Compose plugin, optionally
GNU Make for fast setup.

```bash
git clone https://github.com/typllukas/soap-availability.git
cd soap-availability
make setup   # containers and dependencies, php + nginx on http://localhost:8082
make demo
```

`make demo` prints this, the order number is a fresh ULID every run and the fault envelope is cut
short here:

```
getStockAvailability  KEYB-01       12 pcs    2499.00 CZK  available
getStockAvailability  MOUSE-01       0 pcs     799.00 CZK  sold out
createOrder           ORD-01M2NP725MK694TS3SDB8818KT  2x KEYB-01 = 4998.00 CZK  (nothing is persisted)
createOrder NOPE      fault UNKNOWN_PRODUCT (productCode=NOPE): a fault envelope, not an HTTP error page:
  <?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope ...><SOAP-ENV:Fault><faultcode>SOAP-ENV:Client</faultcode>...</SOAP-ENV:Fault>...
NumberToDollars       4998.00 -> four thousand nine hundred and ninety eight dollars  (SOAP 1.2, dataaccess.com)
```

The contract in a browser: `http://localhost:8082/stock-availability?wsdl`. Port 8082 is the default,
`APP_PORT=9090 make setup` moves it.

### Tests

The api tests drive a real `SoapServer` through the kernel. A mock would skip the encoding, the
classmap and the fault detail, which is where SOAP actually breaks. No web server has to be up.

## What ext-soap leaves to us

- Does not unwrap parameters. Each operation takes one wrapper object, mapped through a `classmap`.
- Does not check a message against the schema. The service does it first, with the schema lifted out
  of the WSDL at runtime so there is no second copy. Without it `<quantity>2.9</quantity>` arrives
  as `2` and the order is accepted.
- Does not apply the `classmap` to a fault detail. The server encodes it from the WSDL, the client
  gets `stdClass` and maps it by hand.
- Gives `xsd:decimal` back as a string and keeps `2499.00`. The float risk is ours, so the catalog
  holds minor units.

## Not here, on purpose

WS-Security, MTOM, a SOAP 1.2 binding in the WSDL.

## Known limits

- No database, the stock never goes down, nothing is stored, so the same order can be placed again.
- A body `ext-soap` cannot decode ends the process inside `handle()`, so it comes back as `Server`
  and nothing logs it.
