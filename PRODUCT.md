# Product

## Register

product

## Users

The staff of a small veterinary clinic: reception, veterinarians, the medical director and the administrator. Reception keeps the panel open all day at the front desk. They answer the phone, check animals in and take payments while looking at the day's agenda. Veterinarians check their own column between consultations and open the clinical record. The director and the admin look at sales, cash and statistics at the end of the day.

## Product Purpose

The PANGI panel is the clinic's CRM/ERP. It covers the agenda and bookings, clinical records, owners and patients, cash register, sales, inventory, purchasing, reminders and attendance. It succeeds when reception can answer "is there an opening at 11?" or "when is Kuyen coming?" in seconds, and when no appointment, payment or record is lost or entered twice.

## Brand Personality

Sober, clinical, precise. The interface stays almost monochrome. Color only shows up where it carries information: the appointments on the agenda, each with its own color, and alerts. The tone is direct and professional, in Chilean Spanish, with no marketing language.

## Anti-references

- Copying AgendaPro's identity (purple, its logo, its exact layout). It is used only as a reference for how to organize the agenda.
- SaaS dashboards with gradients, glassmorphism or hero metrics.
- Cartoonish pet themes: paws everywhere, playful colors, rounded childlike type.
- Decorative color: a state that can only be told apart by its color.

## Design Principles

1. **The day reads at a glance.** The agenda is the main screen. Who is attending, at what time and what is free must be visible without opening anything.
2. **Color is information, not decoration.** The neutral chrome leaves room for appointments and alerts to stand out.
3. **Never only color.** Each appointment has a distinct color, but its state (confirmed, completed, cancelled) is also shown with text or an icon.
4. **Density with legibility.** A lot of information per screen, never below the type-scale floor (`--t-micro`).
5. **Nothing gets lost.** Moving, cancelling or blocking an hour leaves a trace, and nothing is deleted silently.

## Accessibility & Inclusion

WCAG 2.2 AA: text ≥ 4.5:1 (the project checks it with `tests/test_legibilidad_frontends.py`). Text on each appointment color has to keep 4.5:1. Every animation respects `prefers-reduced-motion`. Full keyboard support in the menus and the agenda. State never depends on color alone (color blindness).
