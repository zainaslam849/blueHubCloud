<template>
    <section class="admin-card">
        <p class="admin-card__kicker">403</p>
        <h1 class="admin-card__headline">Access denied</h1>
        <p class="admin-card__hint">
            Your account doesn’t have access to the admin area.
        </p>

        <div class="admin-card__actions">
            <RouterLink class="admin-btn admin-btn--secondary" to="/login">
                Back to login
            </RouterLink>
            <a class="admin-btn admin-btn--ghost" href="/">Go to site</a>
        </div>
    </section>
</template>
