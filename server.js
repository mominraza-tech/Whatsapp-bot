// =========================
// ✅ WhatsApp Order Bot – FINAL CLEAN VERSION
// =========================
const express = require("express");
const fetch = require("node-fetch"); // node-fetch@2
const app = express();
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// ==== CONFIG ====
const PHONE_ID = "759445450592570";
const TOKEN = "EAAL1Sva7easBPmMVhAjJC2ovVEwHymIMHACvQMu2UIlZAZAtZBGFIXHYVuG7ELNmKiML94V9xsWVDSneZCrm7fZAP94mGs40ZApiN66uIaM1nS5VBYXIXJ53mZBTA62ZBBZBTZBJpuVAd91H1mj4gUGf7NernoMAaAnK7N7gZC615HrLc4NaAn3atrJDG89t8OPL2qxPgZDZD";
const VERIFY_TOKEN = "momin_secret_token";
const TEMPLATE_NAME = "order_action";
const WC_AUTH =
  "Basic " +
  Buffer.from(
    "ck_ffcd88a1d880ee99dc95b5194c181fa896e59bf3:cs_7552aba5e81019e7d1695ece97e2fb648c7a2ab3"
  ).toString("base64");
// =================

// In-memory store
const orders = {};
const lastOrderByPhone = {};
const processedMessageIds = new Set();

// === SEND TEMPLATE MESSAGE ===
async function sendTemplateMessage(toPhone, customerName, orderId) {
  const formattedPhone = toPhone.replace(/^\+/, "");
  const url = `https://graph.facebook.com/v20.0/${PHONE_ID}/messages`;
  const payload = {
    messaging_product: "whatsapp",
    to: formattedPhone,
    type: "template",
    template: {
      name: TEMPLATE_NAME,
      language: { code: "en" },
      components: [
        {
          type: "body",
          parameters: [
            { type: "text", text: customerName },
            { type: "text", text: orderId },
          ],
        },
      ],
    },
  };

  const res = await fetch(url, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });

  const text = await res.text();
  try {
    console.log("📤 WhatsApp Template Response:", JSON.parse(text));
  } catch {
    console.log("📤 WhatsApp Template Response:", text);
  }
}

// === SEND TEXT MESSAGE ===
async function sendTextMessage(toPhone, textMessage) {
  const formattedPhone = toPhone.replace(/^\+/, "");
  const url = `https://graph.facebook.com/v20.0/${PHONE_ID}/messages`;
  const payload = {
    messaging_product: "whatsapp",
    to: formattedPhone,
    type: "text",
    text: { body: textMessage },
  };

  const res = await fetch(url, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });
  const txt = await res.text();
  try {
    console.log("📤 Text send response:", JSON.parse(txt));
  } catch {
    console.log("📤 Text send response:", txt);
  }
}

// === ORDER ENDPOINT (FROM WOOCOMMERCE) ===
app.post("/order", async (req, res) => {
  try {
    console.log("🟡 Incoming WooCommerce Payload:", JSON.stringify(req.body, null, 2));

    const data = req.body;
    if (!data.billing || !data.id) {
      console.warn("⚠️ Ignored webhook without order details:", data);
      return res.status(200).json({ skipped: true });
    }

    const orderId = `ORD-${data.id}`;
    const name = `${data.billing.first_name || "Customer"} ${data.billing.last_name || ""}`.trim();
    const phone = (data.billing.phone || "").replace(/[^0-9+]/g, "");
    if (!phone) {
      console.error("❌ Missing phone number");
      return res.status(200).json({ skipped: true });
    }

    orders[orderId] = { name, phone, status: "pending" };
    lastOrderByPhone[phone.replace(/^\+/, "")] = orderId;

    console.log(`🧾 New order received: ${name} ${phone} ${orderId}`);
    await sendTemplateMessage(phone, name, orderId);
    res.json({ ok: true });
  } catch (err) {
    console.error("Order endpoint error:", err);
    res.status(500).json({ error: "server error" });
  }
});

// === META VERIFY ===
app.get("/webhook", (req, res) => {
  const mode = req.query["hub.mode"];
  const token = req.query["hub.verify_token"];
  const challenge = req.query["hub.challenge"];
  if (mode === "subscribe" && token === VERIFY_TOKEN) {
    console.log("✅ Webhook verified");
    return res.status(200).send(challenge);
  }
  res.sendStatus(403);
});

// === HANDLE USER REPLY ===
app.post("/webhook", async (req, res) => {
  try {
    const msg = req.body.entry?.[0]?.changes?.[0]?.value?.messages?.[0];
    if (!msg) return res.sendStatus(200);

    const msgId = msg.id;
    if (processedMessageIds.has(msgId)) return res.sendStatus(200);
    processedMessageIds.add(msgId);

    const from = msg.from;
    let userReply = msg.text?.body?.trim() || null;
    if (msg.type === "button" && msg.button?.text) {
      userReply = msg.button.text.trim();
    }

    console.log(`💬 User reply from ${from}: ${userReply}`);
    const orderId = lastOrderByPhone[from];
    if (!orderId) {
      await sendTextMessage(from, "We could not find your order. Please provide your order number or contact support.");
      return res.sendStatus(200);
    }

    const replyLower = (userReply || "").toLowerCase();
    const isConfirm = replyLower === "1" || replyLower.includes("confirm");
    const isCancel = replyLower === "2" || replyLower.includes("cancel");

    const wcOrderId = orderId.replace("ORD-", "");

    // ✅ CONFIRM HANDLER
    if (isConfirm) {
      orders[orderId].status = "confirmed";
      console.log(`✅ Order ${orderId} CONFIRMED by ${from}`);
      await sendTextMessage(from, `✅ Your order ${orderId} has been confirmed. Thank you!`);

      const response = await fetch(`https://www.scentnskin.com/wp-json/wc/v3/orders/${wcOrderId}`, {
        method: "PUT",
        headers: {
          Authorization: WC_AUTH,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          status: "processing",
          meta_data: [{ key: "_whatsapp_confirmed", value: "yes" }],
        }),
      });

      const resText = await response.text();
      console.log("🔍 WooCommerce response (Confirm):", response.status, resText);

      // Add order note
      await fetch(`https://www.scentnskin.com/wp-json/wc/v3/orders/${wcOrderId}/notes`, {
        method: "POST",
        headers: {
          Authorization: WC_AUTH,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          note: "✅ WhatsApp confirmed. Triggering new order email.",
        }),
      });
    }
// ❌ CANCEL HANDLER (Final FIXED version)
else if (isCancel) {
  console.log(`❌ Order ${orderId} CANCELLED by ${from}`);
  orders[orderId].status = "cancelled";

  // Notify customer on WhatsApp
  await sendTextMessage(from, `❌ Your order ${orderId} has been cancelled successfully.`);

  const wcOrderId = orderId.replace("ORD-", "");

  try {
    // Step 1 — Force WooCommerce status to "cancelled"
    const cancelResponse = await fetch(`https://www.scentnskin.com/wp-json/wc/v3/orders/${wcOrderId}`, {
      method: "PUT",
      headers: {
        Authorization: WC_AUTH,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        status: "cancelled",
        set_paid: true, // ✅ Must be true to bypass pending restriction
        meta_data: [
          { key: "_whatsapp_confirmed", value: "no" },
          { key: "_order_cancel_reason", value: "Cancelled via WhatsApp by customer." },
        ],
      }),
    });

    const resText = await cancelResponse.text();
    console.log("🔍 WooCommerce response (Cancel):", cancelResponse.status, resText);

    // Step 2 — Add internal note
    await fetch(`https://www.scentnskin.com/wp-json/wc/v3/orders/${wcOrderId}/notes`, {
      method: "POST",
      headers: {
        Authorization: WC_AUTH,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        note: "❌ Order cancelled via WhatsApp by customer.",
      }),
    });

    // Step 3 — Trigger both admin + customer cancellation emails
    const emailEndpoints = [
      "cancelled_order",
      "customer_cancelled_order",
    ];

    for (const email_id of emailEndpoints) {
      await fetch(`https://www.scentnskin.com/wp-json/wc/v3/orders/${wcOrderId}/actions/send_email`, {
        method: "POST",
        headers: {
          Authorization: WC_AUTH,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ email_id }),
      });
    }

    console.log(`✅ Order ${orderId} successfully cancelled in WooCommerce and emails sent.`);
  } catch (err) {
    console.error("❌ Error cancelling order via WooCommerce API:", err);
  }
}



    res.sendStatus(200);
  } catch (err) {
    console.error("Webhook handler error:", err);
    res.sendStatus(500);
  }
});

// === START SERVER ===
app.listen(3000, () => console.log("🚀 Server running on port 3000"));
