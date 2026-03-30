// Fonction pour créer un cookie sécurisé
export function setCookie(name, value, maxAgeInSeconds = 60 * 60) {
    document.cookie = `${name}=${value}; Path=/; Max-Age=${maxAgeInSeconds}; Secure; SameSite=Strict`;
    // document.cookie = `${name}=${value}; Path=/; Max-Age=${maxAgeInSeconds}; Secure; SameSite=None`;
}

// Fonction pour obtenir la valeur d'un cookie
export function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(";").shift();
    return null;
}

// Fonction pour supprimer un cookie
export function deleteCookie(name) {
    if (getCookie(name)) {
        document.cookie = `${name}=; Expires=Thu, 01 Jan 1970 00:00:00 UTC; Path=/;`;
    }
}