(function () {
    const BACKEND_URL = cleanconvert_vars.backend_url;
    const TOKEN = cleanconvert_vars.token;

    const ALL_PARAMS = [
        "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "utm_id",
        "gclid", "gbraid", "wbraid", "dclid", "gclsrc", "gad_source", "gad_campaignid",
        "fbclid", "ttclid", "msclkid", "li_fat_id", "epik", "twclid", "ScCid", "rdt_cid",
        "vmcid", "utm_source_platform", "utm_creative_format", "utm_marketing_tactic",
        "utm_campaign_id", "utm_adgroup", "utm_adgroup_id", "ad_id", "utm_remarketing",
        "ref", "irclickid", "clickid"
    ];

    // 1. Capture URL params into localStorage
    function captureParams() {
        const params = new URLSearchParams(window.location.search);
        const stored = JSON.parse(localStorage.getItem('cc_params') || '{}');
        ALL_PARAMS.forEach(key => {
            if (params.has(key)) stored[key] = params.get(key);
        });
        localStorage.setItem('cc_params', JSON.stringify(stored));
    }

    // 2. Get stored params
    function getStoredParams() {
        return JSON.parse(localStorage.getItem('cc_params') || '{}');
    }

    // 3. Build base event data
    function getBaseData() {
        return {
            url: window.location.href,
            referrer: document.referrer,
            user_agent: navigator.userAgent,
            ...getStoredParams()
        };
    }

    // 4. Send event to backend
    function sendEvent(type, data) {
        fetch(BACKEND_URL + '/webhook/wordpress?type=' + type, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CleanConvert-Token': TOKEN
            },
            body: JSON.stringify({ ...getBaseData(), ...data })
        }).catch(() => { });
    }

    // 5. Fire events
    captureParams();

    // viewPage — every page
    sendEvent('viewPage', {
        page_title: document.title,
    });

    // initiateCheckout — on checkout page
    if (cleanconvert_vars.is_checkout) {
        sendEvent('initiateCheckout', {
            page_title: document.title,
        });
    }

    // purchase — on order received page
    if (cleanconvert_vars.is_order_received && cleanconvert_vars.order) {
        sendEvent('purchase', cleanconvert_vars.order);
    }

    // addToCart — hook into WooCommerce AJAX
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.add_to_cart_button, .single_add_to_cart_button');
        if (!btn) return;
        const productId = btn.dataset.product_id || btn.value || '';
        const productName = btn.dataset.product_name || document.title || '';
        sendEvent('addToCart', {
            product_id: productId,
            product_name: productName,
        });
    });

})();