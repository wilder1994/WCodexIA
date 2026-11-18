/** Lista las versiones y envía la decisión de aprobar o rechazar. */

const list = document.querySelector("#list");
const statusLine = document.querySelector("#status");

const labels = {
  pending: "Pendiente",
  approved: "Aprobada",
  rejected: "Rechazada",
  train_samples: "Muestras de entrenamiento",
  eval_samples: "Muestras de evaluación",
  train_loss: "Pérdida de entrenamiento",
  eval_loss: "Pérdida de evaluación",
  score: "Puntaje",
};

function showMetric(value) {
  return value === null || value === undefined || value === "" ? "sin dato" : String(value);
}

function text(tag, className, value) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  node.textContent = value;
  return node;
}

function card(version) {
  const article = document.createElement("article");
  article.className = "card";

  const head = document.createElement("div");
  head.className = "head";
  head.append(text("h2", "", version.label || version.id));
  head.append(text("span", `badge ${version.status}`, labels[version.status] || version.status));

  const meta = text(
    "p",
    "meta",
    `Base ${version.base_model || "sin base"} · adaptador ${version.adapter || "ninguno"} · ${version.created_at || ""}`,
  );

  const metrics = document.createElement("div");
  metrics.className = "metrics";
  Object.entries(version.metrics || {}).forEach(([key, value]) => {
    const box = document.createElement("div");
    box.className = "metric";
    box.append(text("span", "", labels[key] || key));
    box.append(text("strong", "", showMetric(value)));
    metrics.append(box);
  });

  const actions = document.createElement("div");
  actions.className = "actions";
  const approve = text("button", "approve", "Aprobar");
  const reject = text("button", "reject", "Rechazar");
  approve.type = "button";
  reject.type = "button";
  approve.addEventListener("click", () => decide(version.id, "approved", article));
  reject.addEventListener("click", () => decide(version.id, "rejected", article));
  actions.append(approve, reject);

  article.append(head, meta, metrics, text("p", "notes", version.notes || ""), actions);
  return article;
}

async function decide(id, decision, article) {
  article.querySelectorAll("button").forEach((button) => {
    button.disabled = true;
  });
  statusLine.textContent = "Guardando la decisión.";
  const response = await fetch(`/api/versions/${encodeURIComponent(id)}/decision`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ status: decision }),
  });
  if (!response.ok) {
    statusLine.textContent = "No se pudo guardar la decisión.";
    article.querySelectorAll("button").forEach((button) => {
      button.disabled = false;
    });
    return;
  }
  await load();
}

let loading = false;

async function load(quiet = false) {
  if (loading) return;
  loading = true;
  if (!quiet) statusLine.textContent = "Cargando versiones.";
  try {
    const response = await fetch("/api/versions");
    if (!response.ok) {
      statusLine.textContent = "No se pudo leer el listado.";
      return;
    }
    const store = await response.json();
    list.replaceChildren();
    const versions = store.versions || [];
    if (versions.length === 0) {
      statusLine.textContent = "Todavía no hay versiones publicadas.";
      return;
    }
    versions.forEach((version) => list.append(card(version)));
    statusLine.textContent = `${versions.length} versión(es).`;
  } finally {
    loading = false;
  }
}

load();
setInterval(() => {
  if (statusLine.textContent === "Guardando la decisión.") return;
  load(true);
}, 15000);
