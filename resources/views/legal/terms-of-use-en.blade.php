@extends('layouts.legal', [
    'title' => 'Terms of Use',
    'description' => 'Terms governing the use of the Maik Cat catalytic converter catalog and valuation application.',
    'lang' => 'en',
    'dir' => 'ltr',
    'alternateUrl' => route('legal.terms.ar'),
    'alternateLabel' => 'العربية',
])

@section('content')
    <span class="eyebrow">Maik Cat Legal</span>
    <h1>Terms of Use</h1>
    <p class="updated">Effective date: {{ config('legal.effective_date') }}</p>

    <div class="notice">
        Maik Cat is an informational catalog and valuation tool for catalytic converters. Values shown in the application are estimates only. They are not guaranteed purchase prices, sale offers, laboratory certificates, or financial advice.
    </div>

    <h2>1. Acceptance of these Terms</h2>
    <p>These Terms of Use govern access to and use of the Maik Cat mobile application, website, APIs, catalog, calculator, market charts, notifications, and related services (collectively, the “Service”). The Service is operated by {{ config('legal.operator_name', 'Maik Cat') }} (the “Operator”, “we”, “us”, or “our”).</p>
    <p>By accessing or using the Service, you confirm that you have read, understood, and agreed to these Terms and the Privacy Policy. If you do not agree, do not use the Service.</p>

    <h2>2. Eligibility and accounts</h2>
    <ul>
        <li>You must be legally capable of entering into a binding agreement in your jurisdiction. The Service is not intended for children.</li>
        <li>Some features require an account created or approved by the Operator. You must provide accurate account information and keep it current.</li>
        <li>You are responsible for maintaining the confidentiality of your password, access token, and device, and for activity performed through your account.</li>
        <li>You must notify support promptly if you suspect unauthorized access.</li>
        <li>We may reject, suspend, deactivate, or terminate an account when necessary to protect the Service, users, data, or legal interests.</li>
    </ul>

    <h2>3. What the Service provides</h2>
    <p>The Service may provide:</p>
    <ul>
        <li>A searchable catalytic converter catalog organized by vehicle group, model, serial code, alternate codes, and images.</li>
        <li>Technical reference data, including item weight and platinum (Pt), palladium (Pd), and rhodium (Rh) assay values.</li>
        <li>Multiple analysis records for the same serial family when weight or assay results differ.</li>
        <li>Estimated values calculated from item data, user-entered values, metal prices, selected rates, units, and humidity or other adjustment factors.</li>
        <li>Metal spot prices, market charts, price-change notifications, related items, and similar items.</li>
        <li>Saved-item lists and account preferences.</li>
        <li>Authorized catalog import and administration tools.</li>
    </ul>
    <p>Features may be changed, added, limited, suspended, or removed at any time.</p>

    <h2>4. Estimates, assays, and market data</h2>
    <p>All calculations and displayed values are estimates. Actual recoverable metal content and commercial value may differ because of sampling methods, laboratory results, converter condition, contamination, moisture, weight variation, refining yield, treatment charges, market spreads, currency conversion, taxes, transport, and other commercial factors.</p>
    <p>Metal prices and charts may be supplied by third-party data providers and may be delayed, incomplete, temporarily unavailable, or different from prices available in a particular market. You must independently verify current prices and conduct appropriate physical inspection and laboratory testing before relying on any result.</p>
    <p>Where the same serial code has multiple analyses, each record represents a separate reference case. A visual or serial-code match does not prove that a physical converter has the same weight, assay, origin, or value.</p>

    <h2>5. No purchase, sale, or payment commitment</h2>
    <p>Unless a separate written agreement expressly states otherwise, the Service does not itself complete a purchase, sale, auction, payment, shipment, refining transaction, or transfer of ownership. A displayed estimate is not a binding offer by the Operator or any third party.</p>
    <p>Any commercial transaction arranged outside the Service is solely between the participating parties and is subject to their own inspection, pricing, payment, compliance, and contractual terms.</p>

    <h2>6. User-entered data and imports</h2>
    <p>When you enter calculator values, upload spreadsheets, submit catalog data, add images, or use import tools, you are responsible for the legality, accuracy, quality, and authorization of that content.</p>
    <ul>
        <li>Do not upload personal, confidential, unlawful, misleading, or infringing material.</li>
        <li>Do not intentionally manipulate weights, assay values, serial codes, market rates, or source information.</li>
        <li>You grant the Operator a non-exclusive license to host, process, reproduce, transform, and display submitted content only as needed to operate, secure, improve, and maintain the Service.</li>
        <li>Import validation, duplicate detection, image matching, or automated processing does not guarantee that submitted data is correct.</li>
    </ul>

    <h2>7. Acceptable use</h2>
    <p>You must not:</p>
    <ul>
        <li>Use the Service for fraud, theft, trafficking in stolen goods, sanctions evasion, money laundering, or any unlawful activity.</li>
        <li>Misrepresent the identity, origin, ownership, composition, condition, or value of a converter.</li>
        <li>Access another user’s account or restricted administration functions without authorization.</li>
        <li>Scrape, copy, bulk-download, resell, republish, or build a competing database from the catalog without written permission.</li>
        <li>Reverse engineer, probe, disrupt, overload, bypass security controls, introduce malware, or exploit vulnerabilities.</li>
        <li>Use automated tools in a manner that degrades availability or exceeds reasonable request limits.</li>
        <li>Remove watermarks, source notices, trademarks, or ownership information from catalog media.</li>
    </ul>

    <h2>8. Saved items and notifications</h2>
    <p>Saved items are provided for convenience and do not reserve a price or guarantee continued catalog availability. Market and application notifications may be delayed or not delivered because of device settings, network conditions, third-party messaging services, or system maintenance. You remain responsible for verifying information directly in the Service.</p>

    <h2>9. Intellectual property</h2>
    <p>The Service, software, user interface, database structure, branding, text, calculations, compiled catalog, and original media are owned by or licensed to the Operator and are protected by applicable intellectual-property laws.</p>
    <p>Some product images, technical data, trademarks, vehicle names, and source materials may belong to their respective owners. Their inclusion is for identification and reference and does not imply sponsorship, endorsement, or affiliation.</p>

    <h2>10. Third-party services and links</h2>
    <p>The Service may rely on hosting providers, email services, notification platforms, market-data providers, image-processing services, and other third parties. Their services may be governed by separate terms and privacy policies. We are not responsible for third-party systems that we do not control.</p>

    <h2>11. Availability and changes</h2>
    <p>We aim to keep the Service available, but we do not guarantee uninterrupted or error-free operation. Maintenance, data-provider outages, internet failures, software defects, security incidents, or events outside our reasonable control may affect availability.</p>
    <p>We may correct catalog records, recalculate values, replace images, change formulas or data sources, and update these Terms. Material changes will be communicated through the Service or another reasonable channel when appropriate.</p>

    <h2>12. Disclaimer of warranties</h2>
    <p>To the maximum extent permitted by law, the Service is provided “as is” and “as available”. We disclaim warranties of accuracy, completeness, merchantability, fitness for a particular purpose, non-infringement, uninterrupted availability, and any warranty arising from trade usage.</p>

    <h2>13. Limitation of liability</h2>
    <p>To the maximum extent permitted by law, the Operator will not be liable for indirect, incidental, special, consequential, exemplary, or lost-profit damages arising from use of or inability to use the Service, reliance on estimates or catalog data, market changes, third-party services, or unauthorized account access.</p>
    <p>Nothing in these Terms excludes liability that cannot legally be excluded.</p>

    <h2>14. Suspension and termination</h2>
    <p>We may suspend or terminate access when you breach these Terms, create security or legal risk, misuse data, interfere with the Service, or when continued access is no longer commercially or technically feasible. Provisions that by their nature should survive termination will remain effective.</p>

    <h2>15. Privacy</h2>
    <p>Our collection and use of personal data is described in the <a href="{{ route('legal.privacy.en') }}">Privacy Policy</a>.</p>

    <h2>16. Governing terms</h2>
    <p>If a separate signed agreement between you and the Operator conflicts with these Terms, the signed agreement controls only for the subject it covers. If any provision is unenforceable, the remaining provisions remain effective.</p>
    @if(config('legal.jurisdiction'))
        <p>These Terms are governed by the laws of {{ config('legal.jurisdiction') }}, without regard to conflict-of-law rules.</p>
    @endif

    <div class="contact-card">
        <h2>Contact</h2>
        <p>Questions about these Terms may be sent to the support contact published in the application.</p>
        @if(config('legal.support_email'))
            <p>Email: <a href="mailto:{{ config('legal.support_email') }}">{{ config('legal.support_email') }}</a></p>
        @endif
        @if(config('legal.support_phone'))
            <p>Phone: {{ config('legal.support_phone') }}</p>
        @endif
        @if(config('legal.website_url'))
            <p>Website: <a href="{{ config('legal.website_url') }}">{{ config('legal.website_url') }}</a></p>
        @endif
    </div>
@endsection
