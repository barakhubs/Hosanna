import * as React from "react";
import { useState, useEffect } from "react";
import { Head, usePage } from "@inertiajs/react";
import ejs from "ejs";
import JsBarcode from "jsbarcode";

export default function ProoductBarcode({
    product,
    barcode_settings,
    template,
}) {
    const shop_name = usePage().props.settings.shop_name;

    const parsedBarcodeSettings = barcode_settings.barcode_settings
        ? JSON.parse(barcode_settings.barcode_settings)
        : {};

    const [settings] = useState({
        containerHeight: parsedBarcodeSettings.container_height || "28mm",
        storeFontSize: parsedBarcodeSettings.store_font_size || "0.8em",
        priceFontSize: parsedBarcodeSettings.price_font_size || "0.8em",
        priceMarginTop: parsedBarcodeSettings.price_margin_top || "-3px",
        priceMarginBottom: parsedBarcodeSettings.price_margin_bottom || "-5px",
        barcodeMarginTop: parsedBarcodeSettings.barcode_margin_top || "-10px",
        barcodeHeight: parsedBarcodeSettings.barcode_height || 35,
        barcodeFontSize: parsedBarcodeSettings.barcode_font_size || 14,
        barcodeWidth: parsedBarcodeSettings.barcode_width || 1.5,
        barcodeFormat: parsedBarcodeSettings.barcode_format || "CODE128",
        productNameMarginTop:
            parsedBarcodeSettings.product_name_margin_top || "-4px",
        productNameFontSize:
            parsedBarcodeSettings.product_name_font_size || "0.7em",
    });

    const [content, setContent] = useState("");
    const [renderedTemplate, setRenderedTemplate] = useState("");

    function generateBarcode() {
        // Ensure that the barcode element is available before calling JsBarcode
        const barcodeElement = document.getElementById('barcode');

        if (!barcodeElement) {
            console.error('Barcode element not found');
            return;
        }

        if (!product.barcode) {
            console.error('Product barcode is missing');
            return;
        }

        if (product.barcode && product.barcode.length > 6) {
            settings.barcodeWidth = 1.2;
        }

        try {
            JsBarcode(barcodeElement, product.barcode, {
                format: settings.barcodeFormat,
                width: settings.barcodeWidth,
                height: settings.barcodeHeight,
                fontSize: settings.barcodeFontSize,
            });
        } catch (error) {
            console.error('Error generating barcode:', error);
        }
    }

    useEffect(() => {
        if (template && template.trim() !== '') {
            const data = {
                product,
                settings,
                shop_name,
                barcode_settings,
            };
            // Render the EJS template with the fetched data
            try {
                const rendered = ejs.render(template, data);
                setRenderedTemplate(rendered);
            } catch (error) {
                console.error('Error rendering template:', error);
                setRenderedTemplate('<div>Error loading barcode template</div>');
            }
        } else {
            console.error('Template is empty or missing');
            setRenderedTemplate('<div>Barcode template not found</div>');
        }
    }, [template]);

    useEffect(() => {
        if (renderedTemplate) {
            // Use a more reliable approach to wait for DOM elements
            const attemptBarcodeGeneration = (attempts = 0) => {
                const maxAttempts = 10;
                const barcodeElement = document.getElementById('barcode');

                if (barcodeElement) {
                    generateBarcode();
                } else if (attempts < maxAttempts) {
                    setTimeout(() => attemptBarcodeGeneration(attempts + 1), 100);
                } else {
                    console.error('Barcode element not found after maximum attempts');
                }
            };

            // Start attempting to generate barcode
            attemptBarcodeGeneration();
        }
    }, [renderedTemplate]);

    return (
        <>
            <Head title="Barcode" />
            <div dangerouslySetInnerHTML={{ __html: renderedTemplate }} />
        </>
    );
}
