# VATSIM Event Hub

The *VATSIM Event Hub* is another project maintained by VATSIM Scandinavia. It lists your vACC's events and lets controllers book shifts or be rostered on them. The Hub sends those bookings to Control Center as event bookings, so they already show in each controller's bookings and calendar subscription.

Connect the Hub to Control Center and members can also see **every published event** in their calendar, in one of two ways, from the Calendar subscription card in Settings:

- [x] **In their own calendar**: a switch adds every event to the member's personal link. Off by default. The link stays the same, so nobody has to subscribe again.
- [x] **As a calendar of its own**: a shared events link (`/api/calendar/events.ics`), the same for everyone, with no token as it holds nothing private. Calendar apps give it its own colour and let members hide it in one click.
- [x] **Named event bookings**: an event booking is labelled with the event it belongs to, e.g. "FAOR_APP - Joburg Rush", whichever way the member chose.
- [x] **Light on the Hub**: Control Center reads the Hub's public API once for all members and caches it for 15 minutes. If the Hub can't be reached, the last list it loaded is served and the Hub is left alone for a minute. With no list known at all, the events calendar answers "try again later", so calendar apps keep the events they already show.

## Setup

Set `EVENT_HUB_URL` to your Hub's address, for example `https://events.example.org`. No API key is needed, as Control Center only reads the Hub's public API (`/api/v1/occurrences`). Cancelled dates and drafts are left out.

--8<-- "reference/environment-variables.md:env-vars-event-hub"

!!! tip "See :material-github: GitHub for more"
    Find out more [about the VATSIM Event Hub project](https://github.com/vatsim-scandinavia/events) by visiting its repository.
