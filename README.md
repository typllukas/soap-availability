# soap-availability

[![check](https://github.com/typllukas/soap-availability/actions/workflows/check.yml/badge.svg)](https://github.com/typllukas/soap-availability/actions/workflows/check.yml)

A learning project about SOAP in PHP: a **stock availability and pricing service** for partner
integrations, built on `ext-soap` in plain Symfony 7.4 on PHP 8.5 — the contract written by hand as a WSDL,
a server behind it, a typed client in front of it, and one call to somebody else's live WSDL.
No database: the stock is a PHP array.

## What it does

    GET   /stock-availability?wsdl     the contract
    POST  /stock-availability          getStockAvailability(partnerId, productCode[]) → product[]
                                       createOrder(partnerId, item[]) → orderNumber, totalPrice, currency
                                       fault StockAvailabilityFault{code, productCode?}

    soap-availability:dev:demo         both operations, a deliberate fault, and NumberToDollars on dataaccess.com

Two roles: provider (the service above) and consumer (`SoapClient` against our contract and against
a foreign one).

## How to run it

Everything runs in Docker; nothing runs on the host. You need Docker with the compose plugin and GNU
make.

    make setup       # containers and dependencies, php + nginx on http://localhost:8082
    make demo        # the demo below
    make check       # phpcs, PHPStan (level 10), Rector, PHPUnit

The application binds `127.0.0.1:8082`. If that port is taken, name another one and read the URLs
below against it. The demo reaches the service inside the compose network, and the tests do not use the
network at all, so neither cares which port you pick.

    APP_PORT=9090 make setup

`tools/CodingStandard/` is a phpcs sniff shared with a sibling repository: it keeps short method
chains on one line, and has nothing to do with SOAP.

`make demo` prints this (the order number is a fresh ULID every run):

    getStockAvailability  KEYB-01       12 pcs    2499.00 CZK  available
    getStockAvailability  MOUSE-01       0 pcs     799.00 CZK  sold out
    createOrder           ORD-01M1KK06R7D5032CX22CB528R5  2x KEYB-01 = 4998.00 CZK  (nothing is persisted)
    createOrder NOPE      fault UNKNOWN_PRODUCT (productCode=NOPE): a fault envelope, not an HTTP error page:
      <?xml version="1.0" encoding="UTF-8"?>
    <SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="urn:soap-availability:stock:v1"><SOAP-ENV:Body><SOAP-ENV:Fault><faultcode>SOAP-ENV:Client</faultcode><faultstring>Unknown product code NOPE.</faultstring><detail><ns1:StockAvailabilityFault><ns1:code>UNKNOWN_PRODUCT</ns1:code><ns1:productCode>NOPE</ns1:productCode></ns1:StockAvailabilityFault></detail></SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>

    NumberToDollars       4998.00 -> four thousand nine hundred and ninety eight dollars  (SOAP 1.2, dataaccess.com)

## Decisions and why

- **The WSDL is written by hand** — the point is the contract, not a generated file. document/literal,
  wrapped. "SOAP is RPC" is only half true: operations, yes, but the binding style used in practice is
  document/literal; `rpc/encoded` is retired and forbidden by the WS-I Basic Profile.
- **The schema checks the shape, the service checks the rules, and both answer `Client`.** The schema
  rejects a missing `partnerId`, a fractional or zero `quantity` and an element it does not declare; the
  service rejects a blank `partnerId`, an unknown product and an order above the stock, which no schema
  can know. Request properties stay nullable in PHP even so: an uninitialized typed property would turn
  a client's mistake into a PHP `Error` deep inside the operation. SOAP 1.1 `Client` says "your message
  is wrong, resending it unchanged will not help", which is the truthful answer for all of it.
- **`soap:address` is rewritten from the request when `?wsdl` is served.** `svcutil` and `wsimport` bake
  that address into the generated proxy, so a fixed `localhost:8082` would send a partner's client to the
  machine it was generated on. The file keeps the local value as its default.
- **The fault is part of the contract.** `wsdl:fault` with a typed element and an enumerated code; the
  client turns it into a typed exception. Compare REST, where the error body is usually the one thing
  OpenAPI does not describe.
- **ext-soap does not unwrap parameters** — each operation takes one wrapper object, mapped through a
  `classmap` to a typed DTO. The classmap plays no part in a fault's detail in either direction: the
  server encodes it from the `wsdl:fault` declaration, and the client's decoder hands back `stdClass`,
  so the client maps it by hand.
- **Prices are `xsd:decimal` on the wire and strings in PHP**, integers in minor units in the catalog.
  ext-soap hands `xsd:decimal` back as a string and keeps `2499.00` intact, so the DTO is typed `string`
  to keep it that way end to end. The float is our own risk, not the extension's: a PHP `249.90` is
  serialised as `249.9`, which is why the catalog holds minor units and one helper does the formatting.
- **A failure inside an operation becomes a Server fault, not an HTML 500, and is logged.** ext-soap
  answers a PHP `Error` inside an operation itself (a missing element leaves a typed property
  uninitialized), so the controller never sees it and it would be lost. A thin object between
  `setObject()` and the service therefore delegates each operation explicitly, logs any unexpected
  failure and throws `SoapFault('Server', 'Internal Error')`, which `handle()` renders into the output
  buffer. `SoapServer::fault()` is deliberately not used: called after `handle()` has thrown, it writes
  past the output buffer and ends the process.
- **The response follows the version of the request envelope.** `SoapServer` answers a SOAP 1.2 envelope
  in SOAP 1.2 whatever the WSDL binds, so the content type is derived from the request and the fault is
  recognised by its element rather than by the 1.1 namespace prefix. A 1.2 fault answered as `text/xml`
  with HTTP 200 is a call a partner's stack records as a success.
- **Nothing is authenticated, and `partnerId` is not a credential.** It is an element of the request,
  checked only for being present, the way a partner integration carries an account reference.
  Authentication in SOAP belongs in a header: a WS-Security `UsernameToken` or a signed assertion
  declared as `soap:header` in the binding, which ext-soap dispatches to a method named after the header
  element, so the check would sit beside the operations and reject as a `Client` fault before either
  runs. It is left out because doing it honestly needs a credential store and a replay window, and
  neither teaches anything more about SOAP than knowing where the header goes.
- **The version is in the namespace, not in the path.** `urn:soap-availability:stock:v1` identifies the
  contract, so a breaking change is a second namespace, a second WSDL and a second address, with the
  first served unchanged until partners have moved.
- **ext-soap has no read timeout option.** `connection_timeout` bounds the connect only; the read is
  bounded by the `default_socket_timeout` ini, which `NumberConversionClient` sets around the call and
  puts back in a `finally`. Both clients bound the connect.
- **HTTP 500 on a fault is the SOAP 1.1 rule** — the error is in the body, and the status is what tells
  the client to look for it there.
- **The foreign service is only in the demo command**, never in tests or CI: a green badge must not depend
  on a third party.
- **A thin client, no transformer layer** — with one client, `classmap` does the mapping; the exception
  is a five-line detail mapping.
- **No SOAP library on either side.** For a server there is nothing current: `besimple/soap-bundle`
  stopped at v0.2.6 in 2015 on Symfony 2, and `laminas/laminas-soap` is in security-only maintenance —
  its `AutoDiscover`, which generates the WSDL from PHP classes, is the route this project deliberately
  does not take. For a client there is one: `phpro/soap-client`, on top of `php-soap/ext-soap-engine`,
  generates typed classes from a WSDL, and that is what I would reach for against a large partner
  contract. Not here: the whole contract is 123 lines in eight files, and `SoapServer` needs the same
  `classmap` and the same DTOs, so a generated client would mean a second set of types for one contract
  — and it would hide the parts this project is about.

## What ext-soap does not do (and this project therefore does for it)

- **validate a message against the schema.** It does not, so the body is checked against the WSDL's own
  inline schema before `SoapServer` sees it, and a violation is a `Client` fault carrying
  `INVALID_REQUEST` and libxml's reason. Without it `<quantity>2.9</quantity>` is truncated to `2` before
  the operation can see it and the order is silently accepted, and a body ext-soap cannot decode ends the
  process inside `handle()`, past the controller's `catch`, as a `Server` fault nothing logs.
- The schema is lifted out of the WSDL at runtime rather than kept as a second file, so there is one
  contract and no copy to drift.

Still not done, and not claimed: WS-Security, MTOM, and a SOAP 1.2 binding in the WSDL.

## Known limits

- `createOrder` persists nothing and does not decrement the stock
- `NumberConversionClient` has no test — it would call a third party
- the WSDL declares only a SOAP 1.1 binding, though the server answers 1.2 envelopes as well
- the served contract is reserialized through DOM, so its attribute order differs from the file in the
  repository; the file stays the source of truth
