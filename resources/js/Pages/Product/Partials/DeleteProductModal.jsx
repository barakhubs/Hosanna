import React from 'react';
import {
    Dialog,
    DialogTitle,
    DialogContent,
    DialogContentText,
    DialogActions,
    Button,
    Typography,
} from '@mui/material';
import WarningIcon from '@mui/icons-material/Warning';
import { router } from '@inertiajs/react';

const DeleteProductModal = ({ open, onClose, product, onConfirmDelete }) => {
    const handleDelete = () => {
        if (product) {
            router.delete(`/products/${product.id}`, {
                onSuccess: () => {
                    onConfirmDelete();
                    onClose();
                },
                onError: (errors) => {
                    console.error('Delete failed:', errors);
                    // You can add error handling here, like showing a toast notification
                }
            });
        }
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            aria-labelledby="delete-dialog-title"
            aria-describedby="delete-dialog-description"
            maxWidth="sm"
            fullWidth
        >
            <DialogTitle
                id="delete-dialog-title"
                sx={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 1,
                    color: 'error.main'
                }}
            >
                <WarningIcon />
                Confirm Delete
            </DialogTitle>
            <DialogContent>
                <DialogContentText id="delete-dialog-description">
                    Are you sure you want to delete this product?
                </DialogContentText>
                {product && (
                    <Typography variant="body2" sx={{ mt: 2, fontWeight: 'bold' }}>
                        Product: {product.name}
                    </Typography>
                )}
                <Typography variant="body2" sx={{ mt: 1, color: 'text.secondary' }}>
                    This action cannot be undone. All associated data including batches, stock records, and transaction history will be permanently removed.
                </Typography>
            </DialogContent>
            <DialogActions>
                <Button onClick={onClose} color="primary">
                    Cancel
                </Button>
                <Button
                    onClick={handleDelete}
                    color="error"
                    variant="contained"
                    autoFocus
                >
                    Delete
                </Button>
            </DialogActions>
        </Dialog>
    );
};

export default DeleteProductModal;
