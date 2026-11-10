<!-- markdownlint-disable first-line-heading -->

There's no Control Center without VATSIM.

Besides supporting [VATSIM Connect](./vatsim-connect.md), Control Center relies on:

 * [VATSIM API][vatsim-api]
 * [VATSIM Core API][vatsim-core-api]
 * [VATSIM ATC Bookings API][vatsim-atc-bookings-api]

!!! tip "Use VATSIM APIs with Control Center"
    See [the configuration manual](../configuration/index.md#vatsim) to get started.

## VATSIM Core API

[VATSIM Core API][vatsim-core-api] is used to retrieve members of a subdivision.

!!! info
    VATSIM Core API key v2 is required to enable this feature, contact VATSIM Tech Department using VATSIM Support to get your key.

The feedback form also reads the submitter's recent flights from the Core API. These endpoints are public, so this needs no key.

## VATSIM data feed

The public [VATSIM data feed][vatsim-data-feed] tells the feedback form who is controlling right now, so those controllers are listed first. It needs no key. The result is cached for `VATSIM_DATA_FEED_CACHE_SECONDS` (default 60), and the form keeps working if the feed is unreachable. Override the URL with `VATSIM_DATA_FEED_URL` if needed.

## VATSIM ATC Bookings API

[VATSIM ATC Bookings API][vatsim-atc-bookings-api] is used get, publish, edit and remove controller bookings.

!!! info
    VATSIM ATC Bookings API key is required to enable this feature, contact VATSIM Tech Department using VATSIM Support to get your key.

[vatsim-api]: https://api.vatsim.net/api/
[vatsim-core-api]: https://vatsim.dev/api/core-api
[vatsim-atc-bookings-api]: https://atc-bookings.vatsim.net/api-doc
[vatsim-data-feed]: https://vatsim.dev/api/data-api/get-network-data
