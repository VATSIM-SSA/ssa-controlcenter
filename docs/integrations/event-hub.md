# VATSIM Event Hub

The *VATSIM Event Hub* is another project maintained by VATSIM Scandinavia. It lists your vACC's events and lets controllers book shifts or be rostered on them. The Hub sends those bookings to Control Center as event bookings, so they already show in each controller's bookings and calendar subscription.

Connect the Hub to Control Center and members can also choose to see **every published event** in their calendar subscription.

- [x] **Opt-in per member**: a switch on the Calendar subscription card in Settings. Off by default, so the calendar shows only the member's own bookings.
- [x] **Same link**: switching changes what the existing link serves. Nobody has to subscribe again.
- [x] **Named event bookings**: with events switched on, an event booking is labelled with the event it belongs to, sitting inside the event's own block.
- [x] **Light on the Hub**: Control Center reads the Hub's public API once for all members and caches it. If the Hub can't be reached, the last list it loaded is served.

## Setup

Set `EVENT_HUB_URL` to your Hub's address, for example `https://events.example.org`. No API key is needed, as Control Center only reads the Hub's public API (`/api/v1/occurrences`). Cancelled dates and drafts are left out.

--8<-- "reference/environment-variables.md:env-vars-event-hub"

!!! tip "See :material-github: GitHub for more"
    Find out more [about the VATSIM Event Hub project](https://github.com/vatsim-scandinavia/events) by visiting its repository.
