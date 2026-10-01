/**
 * WTF Lounge - Interactive JavaScript
 * Handles search autocomplete, mobile navigation, and UI interactions
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // =========================================
    // MOBILE NAVIGATION
    // =========================================
    const navToggle = document.getElementById('navToggle');
    const navClose = document.getElementById('navClose');
    const sideNav = document.getElementById('sideNav');
    const sideNavBackdrop = document.getElementById('sideNavBackdrop');

    function openNav() {
        if (sideNav) {
            sideNav.classList.add('is-open');
            sideNav.setAttribute('aria-hidden', 'false');
        }
        if (sideNavBackdrop) {
            sideNavBackdrop.classList.add('is-open');
        }
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', 'true');
        }
        document.body.style.overflow = 'hidden';
    }

    function closeNav() {
        if (sideNav) {
            sideNav.classList.remove('is-open');
            sideNav.setAttribute('aria-hidden', 'true');
        }
        if (sideNavBackdrop) {
            sideNavBackdrop.classList.remove('is-open');
        }
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', 'false');
        }
        document.body.style.overflow = '';
    }

    if (navToggle) {
        navToggle.addEventListener('click', openNav);
    }
    if (navClose) {
        navClose.addEventListener('click', closeNav);
    }
    if (sideNavBackdrop) {
        sideNavBackdrop.addEventListener('click', closeNav);
    }

    // Close nav on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeNav();
        }
    });

    // =========================================
    // SEARCH AUTOCOMPLETE
    // =========================================
    const searchInput = document.querySelector('.search-bar input[name="q"]');
    let autocompleteTimeout = null;
    let autocompleteContainer = null;

    if (searchInput) {
        // Create autocomplete dropdown container
        autocompleteContainer = document.createElement('div');
        autocompleteContainer.className = 'autocomplete-dropdown';
        autocompleteContainer.style.display = 'none';
        searchInput.parentNode.appendChild(autocompleteContainer);

        // Position the dropdown relative to the search input
        function positionDropdown() {
            const rect = searchInput.getBoundingClientRect();
            autocompleteContainer.style.position = 'absolute';
            autocompleteContainer.style.top = (rect.height + 8) + 'px';
            autocompleteContainer.style.left = '0';
            autocompleteContainer.style.right = '0';
            autocompleteContainer.style.width = '100%';
        }

        // Fetch autocomplete suggestions
        async function fetchSuggestions(query) {
            if (query.length < 2) {
                autocompleteContainer.style.display = 'none';
                return;
            }

            try {
                const response = await fetch(`autocomplete.php?q=${encodeURIComponent(query)}`);
                const data = await response.json();
                
                if (data.suggestions && data.suggestions.length > 0) {
                    renderSuggestions(data.suggestions);
                    autocompleteContainer.style.display = 'block';
                    positionDropdown();
                } else {
                    autocompleteContainer.style.display = 'none';
                }
            } catch (error) {
                console.error('Autocomplete error:', error);
                autocompleteContainer.style.display = 'none';
            }
        }

        // Render suggestions
        function renderSuggestions(suggestions) {
            autocompleteContainer.innerHTML = '';
            
            suggestions.forEach(function(item) {
                const suggestion = document.createElement('div');
                suggestion.className = 'autocomplete-item';
                
                // Highlight the matching part
                const searchTerm = searchInput.value.trim();
                if (searchTerm && item.toLowerCase().includes(searchTerm.toLowerCase())) {
                    const index = item.toLowerCase().indexOf(searchTerm.toLowerCase());
                    const before = item.substring(0, index);
                    const match = item.substring(index, index + searchTerm.length);
                    const after = item.substring(index + searchTerm.length);
                    suggestion.innerHTML = before + '<strong>' + match + '</strong>' + after;
                } else {
                    suggestion.textContent = item;
                }
                
                suggestion.addEventListener('click', function() {
                    searchInput.value = item;
                    autocompleteContainer.style.display = 'none';
                    // Submit the form automatically
                    const form = searchInput.closest('form');
                    if (form) {
                        form.submit();
                    }
                });
                
                // Keyboard navigation
                suggestion.setAttribute('tabindex', '0');
                suggestion.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        searchInput.value = item;
                        autocompleteContainer.style.display = 'none';
                        const form = searchInput.closest('form');
                        if (form) {
                            form.submit();
                        }
                    }
                });
                
                autocompleteContainer.appendChild(suggestion);
            });
        }

        // Input event with debounce
        searchInput.addEventListener('input', function() {
            clearTimeout(autocompleteTimeout);
            const query = this.value.trim();
            
            if (query.length < 2) {
                autocompleteContainer.style.display = 'none';
                return;
            }
            
            autocompleteTimeout = setTimeout(function() {
                fetchSuggestions(query);
            }, 300);
        });

        // Close autocomplete on blur (with delay to allow click)
        searchInput.addEventListener('blur', function() {
            setTimeout(function() {
                autocompleteContainer.style.display = 'none';
            }, 200);
        });

        // Close autocomplete on Escape
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                autocompleteContainer.style.display = 'none';
                this.blur();
            }
        });

        // Handle arrow keys for navigation
        let selectedIndex = -1;
        searchInput.addEventListener('keydown', function(e) {
            const items = autocompleteContainer.querySelectorAll('.autocomplete-item');
            if (items.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                highlightItem(items, selectedIndex);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                highlightItem(items, selectedIndex);
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                const selected = items[selectedIndex];
                if (selected) {
                    searchInput.value = selected.textContent;
                    autocompleteContainer.style.display = 'none';
                    const form = searchInput.closest('form');
                    if (form) {
                        form.submit();
                    }
                }
            }
        });

        function highlightItem(items, index) {
            items.forEach(function(item, i) {
                if (i === index) {
                    item.classList.add('selected');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('selected');
                }
            });
        }

        // Re-position on scroll/resize
        window.addEventListener('scroll', function() {
            if (autocompleteContainer.style.display !== 'none') {
                positionDropdown();
            }
        });
        window.addEventListener('resize', function() {
            if (autocompleteContainer.style.display !== 'none') {
                positionDropdown();
            }
        });
    }

    // =========================================
    // TOAST NOTIFICATIONS
    // =========================================
    function showToast(message, duration) {
        duration = duration || 3000;
        let toast = document.querySelector('.toast');
        
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'toast';
            document.body.appendChild(toast);
        }
        
        toast.textContent = message;
        toast.classList.add('is-visible');
        
        setTimeout(function() {
            toast.classList.remove('is-visible');
        }, duration);
    }

    // Check for flash messages and show as toast
    const flashElement = document.querySelector('.flash-msg');
    if (flashElement) {
        const message = flashElement.textContent;
        showToast(message, 4000);
        // Remove the flash element after showing toast
        setTimeout(function() {
            flashElement.style.display = 'none';
        }, 4000);
    }

    // =========================================
    // CART QUANTITY UPDATES (AJAX)
    // =========================================
    const stepperForms = document.querySelectorAll('.stepper form');
    stepperForms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const productId = formData.get('product_id');
            const action = formData.get('action');
            
            fetch('cart_ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(formData)
            })
            .then(function(response) {
                if (response.redirected) {
                    window.location.href = response.url;
                }
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    // Update the quantity display
                    const stepper = form.closest('.stepper');
                    const quantitySpan = stepper.querySelector('span');
                    if (quantitySpan) {
                        quantitySpan.textContent = data.qty || 0;
                    }
                    
                    // Update the cart badge
                    const badge = document.querySelector('.cart-icon__badge');
                    if (badge && typeof data.cart_count !== 'undefined') {
                        badge.textContent = data.cart_count;
                    }
                    
                    // Update the line total
                    const cartLine = form.closest('.cart-line');
                    if (cartLine && typeof data.line_total !== 'undefined') {
                        const priceElement = cartLine.querySelector('.cart-line__price');
                        if (priceElement) {
                            priceElement.textContent = '₱' + data.line_total.toFixed(2);
                        }
                    }
                    
                    // Update subtotal
                    if (typeof data.subtotal !== 'undefined') {
                        const subtotalElements = document.querySelectorAll('.summary-total__value, .summary-row span:last-child');
                        subtotalElements.forEach(function(el) {
                            if (el.textContent.includes('₱')) {
                                el.textContent = '₱' + data.subtotal.toFixed(2);
                            }
                        });
                    }
                }
            })
            .catch(function(error) {
                console.error('Error updating cart:', error);
            });
        });
    });

    // =========================================
    // PRODUCT FILTER (chips)
    // =========================================
    const chips = document.querySelectorAll('.chip');
    chips.forEach(function(chip) {
        chip.addEventListener('click', function(e) {
            // Remove selected class from all chips
            chips.forEach(function(c) {
                c.classList.remove('is-selected');
            });
            // Add selected class to clicked chip
            this.classList.add('is-selected');
        });
    });

    // =========================================
    // CHECKOUT: Toggle address field visibility
    // =========================================
    const modeRadios = document.querySelectorAll('input[name="mode"]');
    const addressField = document.getElementById('addressField');
    
    modeRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            if (addressField) {
                addressField.style.display = this.value === 'Pickup' ? 'none' : 'block';
            }
        });
    });

    // =========================================
    // PAYMENT METHOD SELECTION
    // =========================================
    const paymentOptions = document.querySelectorAll('.payment-option');
    paymentOptions.forEach(function(option) {
        option.addEventListener('click', function() {
            const radio = this.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
                // Trigger the change event
                const event = new Event('change', { bubbles: true });
                radio.dispatchEvent(event);
            }
        });
    });

    // =========================================
    // SMOOTH SCROLL FOR "Browse Menu" button
    // =========================================
    const browseMenuBtn = document.querySelector('a[href="#menu"]');
    if (browseMenuBtn) {
        browseMenuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.getElementById('menu');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    console.log('WTF Lounge — Interactive features loaded!');
});