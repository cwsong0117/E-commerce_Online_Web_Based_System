// GLOBAL VARIABLE: Store applied voucher data (needs to be accessible everywhere)
var appliedVoucher = null;

$(document).ready(function() {
    // Batch delete selected items
    $('#delete-selected-btn').click(function() {
        var selectedIds = [];
        $('.item-checkbox:checked').each(function() {
            selectedIds.push($(this).data('item-id'));
        });
        if (selectedIds.length === 0) {
            alert('Please select items to delete.');
            return;
        }
        if (!confirm('Are you sure you want to delete the selected items?')) {
            return;
        }
        // AJAX batch delete
        $.ajax({
            url: '../../controller/CartController.php',
            type: 'POST',
            data: {
                action: 'batch_delete',
                cart_item_ids: selectedIds
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Remove rows from table
                    selectedIds.forEach(function(id) {
                        $('tr[data-item-id="' + id + '"]').remove();
                    });
                    localStorage.removeItem('checkedItem');
                    restoreCheckboxStates();
                    updateOrderSummary();
                    updateCartCount();
                } else {
                    alert('Failed to delete selected items.');
                    localStorage.removeItem('checkedItem');
                    restoreCheckboxStates();
                }
            }
        });
        
    });

    // refresh the amount of items in the cart icon
    updateCartCount();

    // Check if returning from checkout with selected items
    const urlParams = new URLSearchParams(window.location.search);
    const returnedItems = urlParams.get('items');
    
    if (returnedItems) {
        // User is returning from checkout - restore their selections
        const itemIds = returnedItems.split(',').map(id => parseInt(id));
        
        // Clear all checkboxes first
        restoreCheckboxStates();
        
        // Restore the previously selected items
        itemIds.forEach(function(itemId) {
            $('.item-checkbox[data-item-id="' + itemId + '"]').prop('checked', true);
        });
        
        // Check if all items are selected
        if ($('.item-checkbox:checked').length === $('.item-checkbox').length) {
            $('#select-all').prop('checked', true);
        }
        
        // Save to localStorage
        localStorage.setItem('checkedItem', JSON.stringify(itemIds));
        
        // Clean up URL (remove the items parameter)
        window.history.replaceState({}, document.title, window.location.pathname);
    } else {
        // Normal page load - clear selections
        localStorage.removeItem('checkedItem');
        $('.item-checkbox').prop('checked', false);
        $('#select-all').prop('checked', false);
    }

    // run initial calculation to set up the totals
    updateOrderSummary();
    
    // handle "Select All" checkbox functionality
    // when user clicks the main checkbox, all item checkboxes follow
    $('#select-all').change(function() {
        $('.item-checkbox').prop('checked', this.checked); // set all item status to checked/unchecked
        
        // Save state to localStorage
        let checkedItem = [];
        $('.item-checkbox:checked').each(function() {
            checkedItem.push($(this).data('item-id'));
        });
        localStorage.setItem('checkedItem', JSON.stringify(checkedItem));
        
        updateOrderSummary(); // Recalculate totals after selection change
    });
    
    // handle individual item checkbox changes
    // when user selects/deselects individual items
    $('.item-checkbox').change(function() {
        // save state to local storage
        let checkedItem = [];
        $('.item-checkbox:checked').each(function() {
            checkedItem.push($(this).data('item-id'));
        });
        // save to local storage
        localStorage.setItem('checkedItem', JSON.stringify(checkedItem));

        if (!this.checked) {
            // if any item is unchecked, uncheck "Select All"
            $('#select-all').prop('checked', false);
        } else if ($('.item-checkbox:checked').length === $('.item-checkbox').length) { // if the checked item match the total amount of items in the cart that check the "Select All"
            // if all items are checked, check "Select All"
            $('#select-all').prop('checked', true);
        }
        updateOrderSummary(); // Recalculate totals after selection change
    });
        
    // handle quantity increase button
    // when user clicks the increase button, increase the amount of relative item by 1
    $(document).on('click', '.plus-btn', function() {
        var $button = $(this);
        var display = $button.siblings('.qty-display'); // Find the quantity display
        var currentVal = parseInt(display.text()); // Get quantity of item in the cart
        var cartItemId = $button.attr('data-item-id');
        
        if (currentVal < 99) { // set the max available item to purchase to 99
            var newQty = currentVal + 1;
            display.text(newQty); // Increase by 1
            updateItemTotal($button.closest('tr')); // Recalculate this row's total
            
            // Update database
            $.ajax({
                url: '../../controller/CartController.php',
                type: 'POST',
                data: { 
                    action: 'updateQuantity',
                    cart_item_id: cartItemId,
                    quantity: newQty
                },
                success: function(response) {
                    // The server limits the quantity to the stock left
                    if (response.success && response.quantity < newQty) {
                        display.text(response.quantity);
                        updateItemTotal($button.closest('tr'));
                        updateOrderSummary();
                        alert('Only ' + response.stock + ' left in stock.');
                    }
                    updateCartCount(); // Update navbar count
                }
            });
        }
    });

    // handle quantity decrease button
    // when user clicks the decrease button, decrease the amount of relative item by 1
    $(document).on('click', '.minus-btn', function() {
        var $button = $(this);
        var display = $button.siblings('.qty-display'); // Find the quantity display
        var currentVal = parseInt(display.text()); // Get quantity of item in the cart
        var cartItemId = $button.attr('data-item-id');
        
        if (currentVal > 1) {
            // If quantity > 1, just decrease it
            var newQty = currentVal - 1;
            display.text(newQty); // Decrease by 1
            updateItemTotal($button.closest('tr')); // Recalculate this row's total
            
            // Update database
            $.ajax({
                url: '../../controller/CartController.php',
                type: 'POST',
                data: { 
                    action: 'updateQuantity',
                    cart_item_id: cartItemId,
                    quantity: newQty 
                },
                success: function() {
                    updateCartCount(); // Update navbar count
                }
            });
        } else if (currentVal === 1) {
            // If quantity = 1, ask user if they want to remove item completely
            if(confirm("Are you sure want to remove the item from your cart?")) {
                var $row = $button.closest('tr');
                
                // Delete from database
                $.ajax({
                    url: '../../controller/CartController.php',
                    type: 'POST',
                    data: { 
                        action: 'delete',
                        cart_item_id: cartItemId 
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $row.remove(); // Remove the row from table
                            updateOrderSummary(); // Recalculate all totals
                            updateCartCount(); // Update navbar count
                        }
                    }
                });
            }
        }
    });
        
    // handle remove button
    // when user clicks the trash icon to remove an item
    $(document).on('click', '.remove-btn', function() {
        if (confirm('Are you sure you want to remove this item?')) {
            var $button = $(this);
            var $row = $button.closest('tr');
            var cartItemId = $button.data('itemId');
            
            // Send AJAX request to delete from database
            $.ajax({
                url: '../../controller/CartController.php',
                type: 'POST',
                data: { 
                    action: 'delete',
                    cart_item_id: cartItemId 
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $row.remove(); // Remove the row from table
                        updateOrderSummary(); // Recalculate all totals
                        updateCartCount(); // Update navbar count
                    } else {
                        alert('Failed to remove item');
                    }
                },
                error: function() {
                    alert('Failed to remove item. Please try again.');
                }
            });
        }
    });

    // modal for voucher selection
    var voucherModal = $('#voucherModal');
    var openModalBtn = $('#selectVoucherBtn');
    var closeModalSpan = $('.close');

    // popup a window after click the select button
    // WHY: When user clicks "Select Voucher", we need to show the modal window
    // so they can browse available vouchers
    openModalBtn.click(function() {
        voucherModal.fadeIn(300); // Show the modal with smooth fade animation

        var currentSubtotal = 0;

        $('.item-checkbox:checked').each(function() {
            var row = $(this).closest('tr'); // Get the table row for this item
            var price = parseFloat(row.find('.item-price').text().replace('RM ', '').replace(',', '')); // get the price of item
            var quantity = parseInt(row.find('.qty-display').text()); // get the quantity of item
            currentSubtotal += price * quantity; // add to subtotal
        });

        // check each voucher and disable if the min spend is not met
        $('.voucher-card').each(function() {
            var minSpend = parseFloat($(this).data('min'));
            var $useBtn = $(this).find('.use-voucher-btn, .unuse-voucher-btn');

            if(currentSubtotal < minSpend) {
                $useBtn.prop('disabled', true).css('opacity', 0.5);
                $(this).attr('title', 'Minimum spend of RM ' + minSpend.toFixed(2) + ' required');
            } else if(!$useBtn.hasClass('unuse-voucher-btn')) {
                $useBtn.prop('disabled', false).css('opacity', 1);
                $(this).removeAttr('title');
            }
        });
    });

    // close the modal when user clicks the "x" button or outside the modal content
    closeModalSpan.click(function() {
        voucherModal.fadeOut(300);
    });

    $(window).click(function(event) {
        if(event.target.id === 'voucherModal') {
            voucherModal.fadeOut(300);
        }
    });

    // handle voucher use buttons
    $(document).on('click', '.use-voucher-btn', function() {
        var $button = $(this);
        
        // Check if button is disabled - prevent action if it is
        if ($button.prop('disabled')) {
            return false; // Stop execution
        }
        
        var voucherCard = $button.closest('.voucher-card');

        appliedVoucher = {
            id: voucherCard.data('id'),
            code: voucherCard.data('code'),
            type: voucherCard.data('type'),
            value: parseFloat(voucherCard.data('value')),
            minSpend: parseFloat(voucherCard.data('min')),
            maxDiscount: parseFloat(voucherCard.data('max')),
            Card: voucherCard
        };

        // disable other voucher use buttons
        $('.voucher-card').not(voucherCard).each(function() {
            $(this).css('opacity', 0.5).addClass('voucher-disabled');
            var $btn = $(this).find('.use-voucher-btn');
            $btn.prop('disabled', true).css('pointer-events', 'none').css('cursor', 'not-allowed');
        });

        // change the button to unuse
        $button.text('Unuse').removeClass('use-voucher-btn').addClass('unuse-voucher-btn');
        voucherCard.addClass('selected-voucher');

        // update order summary
        updateOrderSummary();
    });

    // handle voucher unuse buttons  
    $(document).on('click', '.unuse-voucher-btn', function() {
        var $button = $(this);
        var voucherCard = $button.closest('.voucher-card');

        // clear the applied voucher
        appliedVoucher = null;

        // enable all voucher use buttons
        $('.voucher-card').each(function() {
            $(this).css('opacity', 1).removeClass('voucher-disabled');
            var $btn = $(this).find('.use-voucher-btn');
            $btn.prop('disabled', false).css('pointer-events', 'auto').css('cursor', 'pointer');
        });

        // change the button back to use
        $button.text('Use').removeClass('unuse-voucher-btn').addClass('use-voucher-btn');

        // remove the highlight
        voucherCard.removeClass('selected-voucher');

        updateOrderSummary();
    });

    updateCheckoutButtonState();
});

// function to enable/disable checkout button based on selected items
function updateCheckoutButtonState(enable) {
    var checkedCount = $('.item-checkbox:checked').length;
    if (enable === true || checkedCount > 0) {
        $('#checkout-btn').prop('disabled', false).removeClass('disabled');
    } else {
        $('#checkout-btn').prop('disabled', true).addClass('disabled');
    }
}

// function to update individual item total
// This calculates price × quantity for one specific row
function updateItemTotal(row) {
    // Extract price from the price column (remove 'RM ' and commas)
    var price = parseFloat(row.find('.item-price').text().replace('RM ', '').replace(',', ''));
    
    // Extract quantity from the quantity display
    var quantity = parseInt(row.find('.qty-display').text());
    
    // Calculate total for this item
    var total = price * quantity;
    
    // Update the total column for this row
    row.find('.item-total').text('RM ' + total.toFixed(2));
    
    // recalculate the amount after increment or decrement
    updateOrderSummary();
}
    
// master function to recalculate all order totals
// This runs whenever anything changes in the cart
function updateOrderSummary() {
    var subtotal = 0; // Initialize subtotal to zero
    var shippingFee = 15.00; // Fixed shipping fee
    var taxRate = 0.06; // 6% tax rate
    // check whether any item is selected
    var itemSelected = $('.item-checkbox:checked').length;

    if (itemSelected === 0) {
        $('#selectVoucherBtn').prop('disabled', true).css('opacity', 0.5);
        $('.checkout-btn').prop('disabled', true).css('opacity', .5).css('cursor', 'not-allowed');
        $('.checkout-btn a').css('pointer-events', 'none');
    } else {
        $('#selectVoucherBtn').prop('disabled', false).css('opacity', 1);
        $('#checkout-btn').prop('disabled', false).css('opacity', 1).css('cursor', 'pointer');
        $('#checkout-btn a').css('pointer-events', 'auto');
    }
    
    // calculate subtotal from ONLY checked items
    // Loop through each checked checkbox to find selected items
    $('.item-checkbox:checked').each(function() {
        var row = $(this).closest('tr'); // Get the table row for this item
        
        // Extract price from price column (remove 'RM ' and commas)
        var price = parseFloat(row.find('.item-price').text().replace('RM ', '').replace(',', ''));
        
        // Extract quantity from quantity display
        var quantity = parseInt(row.find('.qty-display').text());
        
        // Add this item's total to subtotal
        subtotal += price * quantity;
    });
    
    // calculate tax and grand total
    var tax = subtotal * taxRate; // Tax = subtotal × 6%
    
    var voucherDiscount = 0;

    if(appliedVoucher && subtotal >= appliedVoucher.minSpend) {
        if(appliedVoucher.type === 'percent') { // Fixed: was 'percentage'
            voucherDiscount = subtotal * (appliedVoucher.value / 100);
        } else if(appliedVoucher.type === 'fixed') {
            voucherDiscount = appliedVoucher.value; // Fixed: just use the value directly
        } else if(appliedVoucher.type === 'freeshipping') { // Fixed: was 'shipping'
            voucherDiscount = shippingFee;
            shippingFee = 0;
        }
    };

    if(voucherDiscount > 0) {
        $('.voucher-discount-applied').show();
        $('#voucher-discount-amount').text('- RM ' + voucherDiscount.toFixed(2)); // Added minus sign
    } else {
        $('.voucher-discount-applied').hide();
    };

    var grandTotal = subtotal + shippingFee + tax - voucherDiscount; // Grand total = subtotal + shipping + tax - discount
    
    // handle case when no items are selected
    if ($('.item-checkbox:checked').length === 0) {
        subtotal = 0;
        tax = 0;
        grandTotal = 0;
        shippingFee = 0; // No shipping if no items


    appliedVoucher = null; // Reset the applied voucher
    
    // hide any voucher selection
    $('.voucher-discount-applied').hide();

    // reset the button to use
    $('.unuse-voucher-btn').text('Use')
    .removeClass('unuse-voucher-btn')
    .addClass('use-voucher-btn');
    $('selected-voucher').removeClass('selected-voucher');
        };
    // update the order summary display
    // Find each summary line and update the last span (the amount)
    $('.summary-line').eq(0).find('span:last').text('RM ' + subtotal.toFixed(2)); // Subtotal
    $('.summary-line').eq(1).find('span:last').text('RM ' + shippingFee.toFixed(2)); // Shipping
    $('.summary-line').eq(2).find('span:last').text('RM ' + tax.toFixed(2)); // Tax
    $('.summary-line.total').find('span:last').text('RM ' + grandTotal.toFixed(2)); // Grand Total
}

function restoreCheckboxStates() {

    // unchecked first however
    $('.item-checkbox').prop('checked', false);
    $('#select-all').prop('checked', false);

    // restore checkbox states from localstorage
    let savedItems = JSON.parse(localStorage.getItem('checkedItem')) || [];
    savedItems.forEach(function(itemId) {
        $(`.item-checkbox[data-item-id='${itemId}']`).prop('checked', true);
    });

    // check whether any checkbox is checked
    let checkedCount = $('.item-checkbox:checked').length;
    let totalCount = $('.item-checkbox').length;        
    
    // update select-all checkbox state
    if (checkedCount === totalCount && totalCount > 0) {
        $('#select-all').prop('checked', true);
    }

    // trigger update of selected item
    if(checkedCount > 0) {
        updateCheckoutButtonState(true)
        updateOrderSummary();
    }

    if (checkedCount === 0) {
        $('#voucherBtn').addClass('disabled').prop('disabled', true);
    } else {
        $('#voucherBtn').removeClass('disabled').prop('disabled', false);
    }
}

// Handle checkout button click - create pending order first
$('#checkout-btn').click(function(e) {
    // Get all checked item IDs
    var selectedItems = [];
    $('.item-checkbox:checked').each(function() {
        selectedItems.push($(this).data('item-id'));
    });
    
    // Check if any items are selected
    if (selectedItems.length === 0) {
        alert('Please select at least one item to checkout');
        return false;
    }
    
    // Disable button and show loading state
    $(this).prop('disabled', true).text('Creating Order...');
    
    // Create pending order in database, pass voucher info if applied
    var voucherData = null;
    if (appliedVoucher) {
        voucherData = {
            id: appliedVoucher.id || null,
            code: appliedVoucher.code,
            type: appliedVoucher.type,
            value: appliedVoucher.value,
            minSpend: appliedVoucher.minSpend,
            maxDiscount: appliedVoucher.maxDiscount || null
        };
    }
    $.ajax({
        url: 'create_pending_order.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            selectedItems: selectedItems,
            voucher: voucherData
        }),
        success: function(response) {
            if (response.success) {
                // After order is created, remove selected items from cart
                $.ajax({
                    url: '../../controller/CartController.php',
                    type: 'POST',
                    data: {
                        action: 'batch_delete',
                        cart_item_ids: selectedItems
                    },
                    dataType: 'json',
                    success: function(deleteResponse) {
                        // Optionally handle delete success/failure
                        // Navigate to checkout with order ID
                        window.location.href = 'checkout.php?order_id=' + response.orderId;
                    },
                    error: function() {
                        // Still proceed to checkout even if delete fails
                        window.location.href = 'checkout.php?order_id=' + response.orderId;
                    }
                });
            } else {
                alert('Failed to create order: ' + response.error);
                $('#checkout-btn').prop('disabled', false).text('Proceed to Checkout');
            }
        },
        error: function(xhr) {
            var errorMsg = 'Failed to create order';
            try {
                var response = JSON.parse(xhr.responseText);
                errorMsg = response.error || errorMsg;
            } catch(e) {}
            alert(errorMsg);
            $('#checkout-btn').prop('disabled', false).text('Proceed to Checkout');
        }
    });
});

function updateCartCount() {
    $.ajax({
        url: '../../views/Cart_Order/get_cart_count.php',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.count > 0) {
                $('#cart-count').text(response.count).show();
            } else {
                $('#cart-count').hide();
            }
        },
        error: function() {
            $('#cart-count').hide();
        }
    });
}