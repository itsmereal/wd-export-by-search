const { createRoot, createElement: h, useState, useEffect } = wp.element;
const { Button, TextControl, CheckboxControl, Spinner } = wp.components;
const apiFetch = wp.apiFetch;

function ExportPostsApp() {
  const [searchString, setSearchString] = useState("");
  const [postTypes, setPostTypes] = useState([]);
  const [selectedPostTypes, setSelectedPostTypes] = useState([]);
  const [fileName, setFileName] = useState("exported_posts.csv");
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    apiFetch({ url: wdes_admin.ajax_url + "?action=wdes_get_post_types" })
      .then((data) => setPostTypes(data))
      .catch(() => setError("Failed to load post types."));

    // Check URL parameters for errors
    const urlParams = new URLSearchParams(window.location.search);
    const errorType = urlParams.get("error");
    const searchParam = urlParams.get("search");

    if (errorType) {
      switch (errorType) {
        case "no_posts":
          setError(`No posts found containing '${searchParam}'`);
          break;
        case "security":
          setError("Security check failed.");
          break;
        case "required":
          setError("Search string and post types are required.");
          break;
      }
      // Remove the error parameter from URL
      const newUrl =
        window.location.pathname +
        window.location.search
          .replace(/[?&]error=[^&]*(&|$)/, "")
          .replace(/[?&]search=[^&]*(&|$)/, "");
      window.history.replaceState({}, "", newUrl);
    }
  }, []);

  const handlePostTypeToggle = (postType) => {
    setSelectedPostTypes((prev) =>
      prev.includes(postType)
        ? prev.filter((type) => type !== postType)
        : [...prev, postType]
    );
  };

  const handleExport = () => {
    if (!searchString || selectedPostTypes.length === 0) {
      setError(
        "Please enter a search string and select at least one post type."
      );
      return;
    }
    setExporting(true);
    setError(null);

    // Create URL with parameters
    const url = new URL(
      wdes_admin.ajax_url.replace("admin-ajax.php", "admin-post.php")
    );
    url.searchParams.append("action", "wdes_export_csv");

    // Create form data
    const formData = new FormData();
    formData.append("wdes_export_nonce", wdes_admin.nonce);
    formData.append("search_string", searchString);
    formData.append("post_types", selectedPostTypes.join(","));
    formData.append("file_name", fileName);

    // Make the fetch request
    fetch(url, {
      method: "POST",
      body: formData,
    })
      .then((response) => {
        const contentType = response.headers.get("Content-Type");
        if (contentType && contentType.includes("application/json")) {
          // This is an error response
          return response.json().then((data) => {
            throw new Error(data.data.message || "Export failed");
          });
        } else if (contentType && contentType.includes("text/csv")) {
          // This is a successful CSV download
          return response.blob();
        }
        throw new Error("Unexpected response type");
      })
      .then((blob) => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
      })
      .catch((error) => {
        setError(error.message);
      })
      .finally(() => {
        setExporting(false);
      });
  };

  const createHiddenInput = (name, value) => {
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = name;
    input.value = value;
    return input;
  };

  return h(
    "div",
    { className: "wrap" },
    h("h1", { className: "wp-heading-inline" }, "WD Export by Search"),
    h("div", { style: { marginBottom: "20px" } }, [
      h(
        "div",
        { style: { color: "#666", fontSize: "13px", lineHeight: "1.4" } },
        [
          h(
            "p",
            { style: { marginBottom: "12px" } },
            "Search and export WordPress content containing specific strings across posts, pages, and custom post types."
          ),
          h("div", { style: { display: "flex", gap: "40px" } }, [
            h("div", { style: { lineHeight: "2" } }, [
              "✓ Content & excerpts",
              h("br"),
              "✓ Custom fields",
            ]),
            h("div", { style: { lineHeight: "2" } }, [
              "✓ Elementor data",
              h("br"),
              "✓ Page templates",
            ]),
            h("div", { style: { lineHeight: "2" } }, [
              "✓ Multiple post types",
              h("br"),
              "✓ CSV export",
            ]),
          ]),
        ]
      ),
    ]),
    error && h("div", { className: "wdes-error-message" }, error),
    h(TextControl, {
      label: "Search String",
      value: searchString,
      onChange: setSearchString,
      className: "regular-text",
    }),
    h(
      "div",
      { className: "wdes-checkbox-group" },
      h("label", null, "Select Post Types:"),
      h(
        "div",
        { className: "wdes-checkbox-list" },
        postTypes.map((type) =>
          h("label", { key: type.value, className: "wdes-checkbox-label" }, [
            h("input", {
              key: `input-${type.value}`,
              type: "checkbox",
              checked: selectedPostTypes.includes(type.value),
              onChange: () => handlePostTypeToggle(type.value),
            }),
            h("span", { key: `span-${type.value}` }, type.label),
          ])
        )
      )
    ),
    h(TextControl, {
      label: "File Name",
      value: fileName,
      onChange: setFileName,
      className: "regular-text",
    }),
    h(
      Button,
      {
        isPrimary: true,
        onClick: handleExport,
        disabled: exporting,
        className: "button button-primary",
      },
      exporting ? h(Spinner) : "Export CSV"
    )
  );
}

document.addEventListener("DOMContentLoaded", function () {
  const root = document.getElementById("wdes-admin-app");
  if (root) {
    createRoot(root).render(h(ExportPostsApp));
  }
});
