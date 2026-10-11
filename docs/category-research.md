# Category and template research

The product is a dealer/partner commission administration and private dealer portal, rather than an ecommerce store. Research used public GitHub searches for dealer commission management and affiliate management dashboards, then inspected the following official repository documentation and component sources. Direct template sites were blocked by the environment egress policy; an exhaustive review of every Internet template is not possible.

| Reference | Evidence inspected | Relevant lesson | Decision |
|---|---|---|---|
| [Tabler](https://github.com/tabler/tabler) | Official README and light dashboard screenshot | Clear summary cards, compact tables, status badges | Adapt information hierarchy; do not introduce Bootstrap alongside Tailwind |
| [AdminLTE](https://github.com/ColorlibHQ/AdminLTE) | Official README | Persistent sidebar and operational navigation | Use focused sidebar, avoid unrelated inventory/store features |
| [CoreUI React](https://github.com/coreui/coreui-free-react-admin-template) | Official README | Modular React admin layout | Adopt reusable page shell and responsive navigation |
| [shadcn/ui](https://github.com/shadcn-ui/ui) | README and Button, Card, Dialog component sources | Accessible composable components with local ownership | Tailwind + Radix dialogs and adapted shadcn button |
| [Tremor](https://github.com/tremorlabs/tremor) | Official README | Data-first charts and dashboards | Use a focused allocation visualization, no fabricated analytics |
| [Affiliate portal](https://github.com/contactHenry/affiliate-management-dashboard) | Public project README | Separate partner/admin views, payout visibility | Private dealer portal; omit public applications, links, and assumed referral trees |
| [Telecom commission reporting](https://github.com/TABEYWICKRAMA/Transparent-Commission-Management-Telecommunication-Domain) | Public project README | Commission transparency and exception review | Surface unresolved rules and audit history; do not reuse telecom business assumptions |
| [Odoo dealer module](https://github.com/hafisabdulrasak/odoo-dealer-management) | Public project README | Dealer, sales, and commission category | Keep sales as an explicitly unconfigured extension point |

Original design: Nexora placeholder branding, warm neutral canvas, deep forest and mint accents, concise financial summaries, explicit preview/pending states, and a responsive private workspace. No purchased template assets or source code were copied from commercial themes. shadcn adaptation is MIT licensed. Laravel support and PHP compatibility checked against official Laravel 13 release notes and framework composer manifest via GitHub.
