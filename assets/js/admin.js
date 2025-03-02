/**
 * Admin scripts
 */
jQuery(document).ready(($) => {
  // Handle tab navigation
  $(".nav-tab").on("click", function (e) {
    e.preventDefault()

    var tab = $(this).attr("href").split("tab=")[1]
    window.location.href = "?page=wp-backup-gdrive&tab=" + tab
  })
})

