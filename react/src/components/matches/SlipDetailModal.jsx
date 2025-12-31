import React, { useMemo } from "react";
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Typography,
  Box,
  Chip,
  Stack,
  Divider,
  Grid,
  Paper,
  LinearProgress,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  IconButton,
  alpha,
  styled,
} from "@mui/material";
import {
  TrendingUp,
  AttachMoney,
  Warning,
  Sports,
  Close,
  Info,
  CalendarToday,
  ShowChart,
  AccountBalanceWallet,
  Security,
  Bolt,
  LocalFireDepartment,
  EmojiEvents,
  Timeline,
  BarChart,
  Whatshot,
  Star,
} from "@mui/icons-material";

// Import your custom theme
import { theme as customTheme } from "../../theme"; // Adjust path as needed

// Map MUI colors to your custom theme
const themeColors = {
  primary: customTheme.colors.accent.primary,
  primaryHover: customTheme.colors.accent.primaryHover,
  secondary: customTheme.colors.accent.secondary,
  success: customTheme.colors.accent.success,
  warning: customTheme.colors.accent.warning,
  error: customTheme.colors.accent.error,
  grey100: customTheme.colors.text.primary,
  grey200: customTheme.colors.text.secondary,
  grey300: customTheme.colors.text.tertiary,
  grey400: customTheme.colors.text.muted,
  grey800: customTheme.colors.background.tertiary,
  grey900: customTheme.colors.background.secondary,
  backgroundPrimary: customTheme.colors.background.primary,
  backgroundSecondary: customTheme.colors.background.secondary,
  backgroundTertiary: customTheme.colors.background.tertiary,
  surfaceCard: customTheme.colors.surface.card,
  borderLight: customTheme.colors.border.light,
  borderMedium: customTheme.colors.border.medium,
};

// Dark theme styled components using your custom theme
const GradientPaper = styled(Paper)(({ theme }) => ({
  background: `linear-gradient(135deg, ${alpha(themeColors.surfaceCard, 0.9)} 0%, ${alpha(
    themeColors.backgroundPrimary,
    0.95
  )} 100%)`,
  backdropFilter: "blur(10px)",
  border: `1px solid ${alpha(themeColors.borderLight, 0.1)}`,
}));

const StatsCard = styled(Paper)(({ color }) => ({
  background: `linear-gradient(135deg, ${alpha(color || themeColors.primary, 0.15)} 0%, ${alpha(
    themeColors.surfaceCard,
    0.8
  )} 100%)`,
  backdropFilter: "blur(5px)",
  border: `1px solid ${alpha(color || themeColors.primary, 0.2)}`,
  transition: "all 0.3s ease",
  "&:hover": {
    transform: "translateY(-4px)",
    boxShadow: `0 12px 32px ${alpha(color || themeColors.primary, 0.2)}`,
  },
}));

const GradientButton = styled(Button)({
  background: `linear-gradient(135deg, ${themeColors.primary} 0%, ${themeColors.secondary} 100%)`,
  color: "white",
  fontWeight: 600,
  textTransform: "none",
  transition: "all 0.3s ease",
  "&:hover": {
    transform: "translateY(-2px)",
    boxShadow: `0 8px 25px ${alpha(themeColors.primary, 0.3)}`,
  },
});

const ConfidenceProgress = styled(LinearProgress)(({ value }) => ({
  height: 10,
  borderRadius: 5,
  backgroundColor: alpha(themeColors.grey800, 0.3),
  boxShadow: `inset 0 2px 4px ${alpha("#000", 0.3)}`,
  "& .MuiLinearProgress-bar": {
    background: `linear-gradient(90deg, 
      ${value > 75 ? themeColors.success : value > 50 ? themeColors.warning : themeColors.error} 0%,
      ${value > 75 ? themeColors.success : value > 50 ? themeColors.warning : themeColors.error} 100%)`,
    borderRadius: 5,
    boxShadow: `0 0 10px ${alpha(
      value > 75
        ? themeColors.success
        : value > 50
          ? themeColors.warning
          : themeColors.error,
      0.5
    )}`,
    animation: "pulse 2s infinite",
    "@keyframes pulse": {
      "0%, 100%": { opacity: 1 },
      "50%": { opacity: 0.8 },
    },
  },
}));

const RiskBadge = styled(Chip)(({ risklevel }) => {
  const colors = {
    low: {
      bg: alpha(themeColors.success, 0.15),
      text: themeColors.success,
      border: alpha(themeColors.success, 0.3),
      glow: alpha(themeColors.success, 0.3),
    },
    medium: {
      bg: alpha(themeColors.warning, 0.15),
      text: themeColors.warning,
      border: alpha(themeColors.warning, 0.3),
      glow: alpha(themeColors.warning, 0.3),
    },
    high: {
      bg: alpha(themeColors.error, 0.15),
      text: themeColors.error,
      border: alpha(themeColors.error, 0.3),
      glow: alpha(themeColors.error, 0.3),
    },
  };

  const riskKey = risklevel?.toLowerCase().includes("low")
    ? "low"
    : risklevel?.toLowerCase().includes("medium")
      ? "medium"
      : "high";
  const color = colors[riskKey] || colors.medium;

  return {
    background: color.bg,
    color: color.text,
    border: `1px solid ${color.border}`,
    fontWeight: 700,
    backdropFilter: "blur(5px)",
    boxShadow: `0 4px 15px ${color.glow}`,
    "& .MuiChip-icon": {
      color: color.text,
    },
  };
});

const SlipDetailModal = ({ open, onClose, slip }) => {
  // Memoize color getters - expensive operations
  const getRiskColor = useMemo(() => {
    return (risk) => {
      if (!risk) return themeColors.secondary;
      const riskLower = risk.toLowerCase();
      if (riskLower.includes("low")) return themeColors.success;
      if (riskLower.includes("medium")) return themeColors.warning;
      if (riskLower.includes("high")) return themeColors.error;
      return themeColors.secondary;
    };
  }, []);

  const getConfidenceColor = useMemo(() => {
    return (score) => {
      if (score >= 75) return themeColors.success;
      if (score >= 50) return themeColors.warning;
      return themeColors.error;
    };
  }, []);

  // Calculate derived values with proper memoization
  const calculatedValues = useMemo(() => {
    if (!slip) return {};

    const stake = slip.stake || 0;
    const totalOdds = slip.total_odds || 1;
    const confidence = slip.confidence_score ? slip.confidence_score * 100 : 0;

    const possibleReturn = stake * totalOdds;
    const potentialProfit = possibleReturn - stake;
    const expectedValue = possibleReturn * (confidence / 100);

    const getRiskLevel = () => {
      if (confidence >= 75) return "Low Risk";
      if (confidence >= 50) return "Medium Risk";
      return "High Risk";
    };

    const riskLevel = slip.risk_level || getRiskLevel();
    const riskColor = getRiskColor(riskLevel);
    const confidenceColor = getConfidenceColor(confidence);

    return {
      possibleReturn,
      potentialProfit,
      expectedValue,
      riskLevel,
      riskColor,
      confidenceColor,
      stake,
      totalOdds,
      confidence,
      matchesCount: slip.legs?.length || slip.matches?.length || 0,
      combinedOdds:
        slip.legs?.reduce(
          (total, leg) => total * (parseFloat(leg.odds) || 1),
          1
        ) || 1,
    };
  }, [slip, getRiskColor, getConfidenceColor]);

  // Memoize currency formatter - create once per slip
  const formatCurrency = useMemo(() => {
    const currency = slip?.currency || "EUR";
    return (amount) => {
      if (!amount) return `€0.00`;
      return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: currency,
        minimumFractionDigits: 2,
      }).format(amount);
    };
  }, [slip?.currency]);

  if (!slip) return null;

  // Memoize stats array - expensive object creation
  const keyStats = useMemo(() => {
    const confidenceIcon = calculatedValues.confidence >= 80 
      ? <Star sx={{ color: themeColors.success }} />
      : calculatedValues.confidence >= 60
        ? <TrendingUp sx={{ color: themeColors.warning }} />
        : calculatedValues.confidence >= 40
          ? <Timeline sx={{ color: themeColors.secondary }} />
          : <Warning sx={{ color: themeColors.error }} />;

    return [
      {
        label: "Confidence Score",
        value: `${calculatedValues.confidence.toFixed(0)}%`,
        icon: confidenceIcon,
        color: calculatedValues.confidenceColor,
        subtext:
          calculatedValues.confidence >= 75
            ? "High Confidence"
            : calculatedValues.confidence >= 50
              ? "Moderate Confidence"
              : "Low Confidence",
      },
      {
        label: "Combined Odds",
        value: calculatedValues.totalOdds.toFixed(2),
        icon: <Whatshot sx={{ color: themeColors.secondary }} />,
        color: themeColors.secondary,
        subtext: `${calculatedValues.matchesCount} selections`,
      },
      {
        label: "Potential Return",
        value: formatCurrency(calculatedValues.possibleReturn),
        icon: <AttachMoney sx={{ color: themeColors.success }} />,
        color: themeColors.success,
        subtext: `Stake: ${formatCurrency(calculatedValues.stake)}`,
      },
      {
        label: "Risk Level",
        value: calculatedValues.riskLevel,
        icon: <Security sx={{ color: calculatedValues.riskColor }} />,
        color: calculatedValues.riskColor,
        subtext: "Based on confidence score",
      },
    ];
  }, [calculatedValues, formatCurrency]);

  // Memoize confidence levels array
  const confidenceLevels = useMemo(() => [
    {
      label: "Low (0-49%)",
      icon: <Warning fontSize="small" />,
      color: themeColors.error,
    },
    {
      label: "Moderate (50-74%)",
      icon: <Timeline fontSize="small" />,
      color: themeColors.warning,
    },
    {
      label: "High (75-100%)",
      icon: <TrendingUp fontSize="small" />,
      color: themeColors.success,
    },
  ], []);

  // Extract inline styles to constants
  const dialogPaperStyle = useMemo(() => ({
    borderRadius: 4,
    background: `linear-gradient(135deg, ${alpha(themeColors.surfaceCard, 0.95)} 0%, ${alpha(
      themeColors.backgroundPrimary,
      0.98
    )} 100%)`,
    backdropFilter: "blur(20px)",
    border: `1px solid ${alpha(themeColors.borderLight, 0.1)}`,
    boxShadow: `0 20px 60px ${alpha("#000", 0.4)}`,
  }), []);

  const dialogTitleStyle = useMemo(() => ({
    borderBottom: `1px solid ${alpha(themeColors.borderLight, 0.1)}`,
    background: `linear-gradient(135deg, ${alpha(themeColors.primary, 0.05)} 0%, ${alpha(
      themeColors.secondary,
      0.05
    )} 100%)`,
    p: 3,
  }), []);

  const closeButtonStyle = useMemo(() => ({
    background: alpha(themeColors.grey800, 0.3),
    border: `1px solid ${alpha(themeColors.borderLight, 0.2)}`,
    "&:hover": {
      background: alpha(themeColors.grey800, 0.5),
    },
  }), []);

  const dialogActionsStyle = useMemo(() => ({
    p: 3,
    borderTop: `1px solid ${alpha(themeColors.borderLight, 0.1)}`,
    background: alpha(themeColors.surfaceCard, 0.5),
  }), []);

  const getColorForStat = useMemo(() => {
    return (colorName) => {
      switch (colorName) {
        case "success":
          return themeColors.success;
        case "warning":
          return themeColors.warning;
        case "error":
          return themeColors.error;
        case "primary":
          return themeColors.primary;
        case "secondary":
          return themeColors.secondary;
        default:
          return themeColors.primary;
      }
    };
  }, []);

  // Additional information data
  const additionalInfo = useMemo(() => [
    {
      label: "Expected Value (EV):",
      value: formatCurrency(calculatedValues.expectedValue),
      color: "success",
    },
    {
      label: "Created:",
      value: slip.created_at
        ? new Date(slip.created_at).toLocaleDateString()
        : "N/A",
      color: "grey",
    },
    {
      label: "Status:",
      value: slip.status || "Active",
      chip: true,
      color:
        slip.status === "Won"
          ? "success"
          : slip.status === "Lost"
            ? "error"
            : slip.status === "Pending"
              ? "warning"
              : "default",
    },
    {
      label: "Currency:",
      value: slip.currency || "EUR",
      color: "grey",
    },
    {
      label: "Analysis Type:",
      value:
        calculatedValues.confidence >= 75
          ? "AI Premium"
          : calculatedValues.confidence >= 50
            ? "AI Standard"
            : "AI Basic",
      chip: true,
      color: "primary",
      variant: "outlined",
    },
  ], [slip, calculatedValues, formatCurrency]);

  return (
    <Dialog
      open={open}
      onClose={onClose}
      maxWidth="lg"
      fullWidth
      PaperProps={{ sx: dialogPaperStyle }}
    >
      <DialogTitle sx={dialogTitleStyle}>
        <Box display="flex" justifyContent="space-between" alignItems="center">
          <Box>
            <Typography
              variant="h4"
              fontWeight="800"
              sx={{
                background: `linear-gradient(135deg, ${themeColors.primary} 0%, ${themeColors.secondary} 100%)`,
                WebkitBackgroundClip: "text",
                WebkitTextFillColor: "transparent",
                backgroundClip: "text",
                display: "flex",
                alignItems: "center",
                gap: 1,
              }}
            >
              <Bolt />
              {slip.slip_id || "Slip Analysis"}
            </Typography>
            <Typography
              variant="body2"
              sx={{ mt: 1, opacity: 0.8, color: themeColors.grey300 }}
            >
              Detailed analysis and predictions • Generated on{" "}
              {new Date(slip.created_at).toLocaleDateString()}
            </Typography>
          </Box>
          <IconButton
            onClick={onClose}
            size="small"
            sx={closeButtonStyle}
          >
            <Close sx={{ color: themeColors.grey400 }} />
          </IconButton>
        </Box>
      </DialogTitle>

      <DialogContent dividers sx={{ p: 3 }}>
        {/* Key Metrics Summary */}
        <Grid container spacing={3} mb={4}>
          {keyStats.map((stat, index) => (
            <Grid item xs={12} sm={6} md={3} key={index}>
              <StatsCard color={stat.color} sx={{ p: 2, height: "100%" }}>
                <Box display="flex" alignItems="center" gap={2} mb={2}>
                  <Box
                    sx={{
                      background: `linear-gradient(135deg, ${alpha(stat.color, 0.2)} 0%, ${alpha(stat.color, 0.05)} 100%)`,
                      borderRadius: 2,
                      p: 1.5,
                      display: "flex",
                      alignItems: "center",
                      justifyContent: "center",
                      border: `1px solid ${alpha(stat.color, 0.3)}`,
                    }}
                  >
                    {stat.icon}
                  </Box>
                  <Typography
                    variant="subtitle2"
                    sx={{ opacity: 0.8, color: themeColors.grey300 }}
                  >
                    {stat.label}
                  </Typography>
                </Box>
                {stat.label === "Risk Level" ? (
                  <RiskBadge
                    label={stat.value}
                    risklevel={calculatedValues.riskLevel}
                    icon={stat.icon}
                    sx={{ width: "100%", height: 40, fontSize: "1rem" }}
                  />
                ) : (
                  <Typography
                    variant="h4"
                    sx={{
                      fontWeight: 800,
                      background: `linear-gradient(135deg, ${stat.color} 0%, ${alpha(stat.color, 0.7)} 100%)`,
                      WebkitBackgroundClip: "text",
                      WebkitTextFillColor: "transparent",
                      backgroundClip: "text",
                      mb: 0.5,
                    }}
                  >
                    {stat.value}
                  </Typography>
                )}
                <Typography
                  variant="caption"
                  sx={{ opacity: 0.7, color: themeColors.grey300 }}
                >
                  {stat.subtext}
                </Typography>
              </StatsCard>
            </Grid>
          ))}
        </Grid>

        {/* Confidence Analysis */}
        <GradientPaper sx={{ p: 3, mb: 3, borderRadius: 3 }}>
          <Typography
            variant="h6"
            gutterBottom
            sx={{
              display: "flex",
              alignItems: "center",
              gap: 1,
              color: themeColors.grey100,
              mb: 3,
            }}
          >
            <ShowChart sx={{ color: themeColors.primary }} /> Confidence
            Analysis
          </Typography>
          <Box sx={{ mb: 2 }}>
            <Box
              sx={{ display: "flex", justifyContent:"space-between", mb: 2 }}
            >
              <Typography variant="body2" sx={{ color: themeColors.grey300 }}>
                Confidence Score:{" "}
                <Box
                  component="span"
                  sx={{ color: themeColors.grey100, fontWeight: 600 }}
                >
                  {calculatedValues.confidence.toFixed(1)}%
                </Box>
              </Typography>
              <Typography
                variant="body2"
                sx={{
                  color: calculatedValues.confidenceColor, // ✅ Color stays here
                  fontWeight: 600,
                  display: "flex",
                  alignItems: "center",
                  gap: 0.5,
                }}
              >
                {calculatedValues.confidence >= 75
                  ? "🔥 High Confidence"
                  : calculatedValues.confidence >= 50
                    ? "⚡ Moderate Confidence"
                    : "⚠️ Low Confidence"}
              </Typography>
            </Box>
            <ConfidenceProgress
              variant="determinate"
              value={calculatedValues.confidence} // ✅ Pass numeric confidence value here
            />
            <Box display="flex" justifyContent="space-between" sx={{ mt: 2 }}>
              {confidenceLevels.map((level, idx) => (
                <Box key={idx} display="flex" alignItems="center" gap={1}>
                  <Box sx={{ color: level.color }}>{level.icon}</Box>
                  <Typography
                    variant="caption"
                    sx={{ color: themeColors.grey300 }}
                  >
                    {level.label}
                  </Typography>
                </Box>
              ))}
            </Box>
          </Box>
        </GradientPaper>

        {/* Match Selections */}
        <GradientPaper sx={{ p: 3, mb: 3, borderRadius: 3 }}>
          <Typography
            variant="h6"
            gutterBottom
            sx={{
              display: "flex",
              alignItems: "center",
              gap: 1,
              color: themeColors.grey100,
              mb: 3,
            }}
          >
            <Sports sx={{ color: themeColors.primary }} /> Match Selections (
            {calculatedValues.matchesCount})
          </Typography>
          {slip.legs?.length > 0 || slip.matches?.length > 0 ? (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow
                    sx={{
                      backgroundColor: alpha(themeColors.surfaceCard, 0.3),
                    }}
                  >
                    {[
                      "Match ID",
                      "Market",
                      "Prediction",
                      "Odds",
                      "Fallback",
                      "Confidence",
                    ].map((header) => (
                      <TableCell
                        key={header}
                        sx={{
                          fontWeight: 700,
                          color: themeColors.grey200,
                          borderColor: alpha(themeColors.borderLight, 0.1),
                        }}
                      >
                        {header}
                      </TableCell>
                    ))}
                  </TableRow>
                </TableHead>
                <TableBody>
                  {(slip.legs || slip.matches || []).map((item, index) => {
                    const matchConfidence =
                      item.confidence ||
                      (calculatedValues.confidence /
                        calculatedValues.matchesCount) *
                        (0.8 + Math.random() * 0.4);

                    return (
                      <TableRow
                        key={index}
                        hover
                        sx={{
                          "&:hover": {
                            backgroundColor: alpha(
                              themeColors.surfaceCard,
                              0.5
                            ),
                          },
                          "& td": {
                            borderColor: alpha(themeColors.borderLight, 0.1),
                          },
                        }}
                      >
                        <TableCell>
                          <Typography
                            variant="body2"
                            fontWeight="600"
                            sx={{ color: themeColors.grey100 }}
                          >
                            {item.match_id || "N/A"}
                          </Typography>
                          {item.match_date && (
                            <Typography
                              variant="caption"
                              sx={{
                                display: "flex",
                                alignItems: "center",
                                gap: 0.5,
                                color: themeColors.grey300,
                              }}
                            >
                              <CalendarToday fontSize="small" />
                              {new Date(item.match_date).toLocaleDateString()}
                            </Typography>
                          )}
                        </TableCell>
                        <TableCell>
                          <Chip
                            label={item.market || item.market_name || "Market"}
                            size="small"
                            sx={{
                              background: alpha(themeColors.secondary, 0.15),
                              color: themeColors.secondary,
                              border: `1px solid ${alpha(themeColors.secondary, 0.3)}`,
                            }}
                          />
                        </TableCell>
                        <TableCell>
                          <Chip
                            label={item.selection || item.outcome || "N/A"}
                            size="small"
                            sx={{
                              bgcolor: alpha(themeColors.primary, 0.15),
                              color: themeColors.primary,
                              fontWeight: 600,
                              border: `1px solid ${alpha(themeColors.primary, 0.3)}`,
                            }}
                          />
                        </TableCell>
                        <TableCell>
                          <Box display="flex" alignItems="center" gap={1}>
                            <Typography
                              variant="body1"
                              fontWeight="700"
                              sx={{ color: themeColors.grey100 }}
                            >
                              {parseFloat(item.odds || 1).toFixed(2)}
                            </Typography>
                            {parseFloat(item.odds || 1) > 5 && (
                              <LocalFireDepartment
                                sx={{
                                  color: themeColors.error,
                                  fontSize: 16,
                                }}
                              />
                            )}
                          </Box>
                        </TableCell>
                        <TableCell>
                          {item.is_fallback ? (
                            <Chip
                              label="Fallback"
                              size="small"
                              sx={{
                                background: alpha(themeColors.warning, 0.15),
                                color: themeColors.warning,
                                border: `1px solid ${alpha(themeColors.warning, 0.3)}`,
                              }}
                            />
                          ) : (
                            <Typography
                              variant="caption"
                              sx={{ color: themeColors.grey300 }}
                            >
                              Primary
                            </Typography>
                          )}
                        </TableCell>
                        <TableCell>
                          <Box
                            sx={{
                              display: "flex",
                              alignItems: "center",
                              gap: 1,
                            }}
                          >
                            <Box
                              sx={{
                                width: 60,
                                height: 6,
                                bgcolor: alpha(themeColors.grey800, 0.3),
                                borderRadius: 3,
                                overflow: "hidden",
                              }}
                            >
                              <Box
                                sx={{
                                  width: `${Math.min(matchConfidence, 100)}%`,
                                  height: "100%",
                                  background: `linear-gradient(90deg, 
                                    ${matchConfidence > 75 ? themeColors.success : matchConfidence > 50 ? themeColors.warning : themeColors.error} 0%,
                                    ${matchConfidence > 75 ? themeColors.success : matchConfidence > 50 ? themeColors.warning : themeColors.error} 100%)`,
                                }}
                              />
                            </Box>
                            <Typography
                              variant="caption"
                              sx={{
                                color:
                                  matchConfidence > 75
                                    ? themeColors.success
                                    : matchConfidence > 50
                                      ? themeColors.warning
                                      : themeColors.error,
                                fontWeight: 600,
                              }}
                            >
                              {Math.min(matchConfidence, 100).toFixed(0)}%
                            </Typography>
                          </Box>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          ) : (
            <Box sx={{ textAlign: "center", py: 4 }}>
              <Sports
                sx={{
                  fontSize: 48,
                  color: themeColors.grey300,
                  opacity: 0.3,
                  mb: 2,
                }}
              />
              <Typography variant="body1" sx={{ color: themeColors.grey300 }}>
                No selections found in this slip
              </Typography>
            </Box>
          )}
        </GradientPaper>

        {/* Financial Analysis */}
        <Grid container spacing={3} mb={3}>
          <Grid item xs={12} md={6}>
            <GradientPaper sx={{ p: 3, borderRadius: 3, height: "100%" }}>
              <Typography
                variant="h6"
                gutterBottom
                sx={{
                  display: "flex",
                  alignItems: "center",
                  gap: 1,
                  color: themeColors.grey100,
                  mb: 3,
                }}
              >
                <AccountBalanceWallet sx={{ color: themeColors.primary }} />{" "}
                Profit/Loss Analysis
              </Typography>
              <Stack spacing={3}>
                <Box>
                  <Typography
                    variant="body2"
                    sx={{ opacity: 0.8, color: themeColors.grey300 }}
                  >
                    Stake Amount
                  </Typography>
                  <Typography
                    variant="h4"
                    sx={{ color: themeColors.primary, fontWeight: 800 }}
                  >
                    {formatCurrency(calculatedValues.stake)}
                  </Typography>
                </Box>
                <Divider
                  sx={{ borderColor: alpha(themeColors.borderLight, 0.1) }}
                />
                <Box>
                  <Typography
                    variant="body2"
                    sx={{ opacity: 0.8, color: themeColors.grey300 }}
                  >
                    Potential Profit
                  </Typography>
                  <Typography
                    variant="h4"
                    sx={{ color: themeColors.success, fontWeight: 800 }}
                  >
                    {formatCurrency(calculatedValues.potentialProfit)}
                  </Typography>
                  <Typography
                    variant="caption"
                    sx={{
                      color: themeColors.success,
                      background: alpha(themeColors.success, 0.15),
                      px: 1,
                      py: 0.5,
                      borderRadius: 1,
                      display: "inline-block",
                      mt: 0.5,
                    }}
                  >
                    {calculatedValues.totalOdds > 1
                      ? `${((calculatedValues.totalOdds - 1) * 100).toFixed(0)}% ROI`
                      : "No profit"}
                  </Typography>
                </Box>
                <Box>
                  <Typography
                    variant="body2"
                    sx={{ opacity: 0.8, color: themeColors.grey300 }}
                  >
                    Risk Amount (Max Loss)
                  </Typography>
                  <Typography
                    variant="h4"
                    sx={{ color: themeColors.error, fontWeight: 800 }}
                  >
                    {formatCurrency(calculatedValues.stake)}
                  </Typography>
                </Box>
              </Stack>
            </GradientPaper>
          </Grid>

          <Grid item xs={12} md={6}>
            <GradientPaper sx={{ p: 3, borderRadius: 3, height: "100%" }}>
              <Typography
                variant="h6"
                gutterBottom
                sx={{
                  display: "flex",
                  alignItems: "center",
                  gap: 1,
                  color: themeColors.grey100,
                  mb: 3,
                }}
              >
                <Info sx={{ color: themeColors.secondary }} /> Additional
                Information
              </Typography>
              <Stack spacing={2.5}>
                {additionalInfo.map((item, idx) => (
                  <Box
                    key={idx}
                    display="flex"
                    justifyContent="space-between"
                    alignItems="center"
                  >
                    <Typography
                      variant="body2"
                      sx={{ opacity: 0.8, color: themeColors.grey300 }}
                    >
                      {item.label}
                    </Typography>
                    {item.chip ? (
                      <Chip
                        label={item.value}
                        size="small"
                        variant={item.variant || "filled"}
                        sx={{
                          background:
                            item.color === "grey"
                              ? alpha(themeColors.grey800, 0.3)
                              : item.color === "default"
                                ? alpha(themeColors.grey900, 0.3)
                                : alpha(getColorForStat(item.color), 0.15),
                          color:
                            item.color === "grey"
                              ? themeColors.grey400
                              : item.color === "default"
                                ? themeColors.grey400
                                : getColorForStat(item.color),
                          border:
                            item.variant === "outlined"
                              ? `1px solid ${item.color === "grey" ? alpha(themeColors.borderMedium, 0.3) : alpha(getColorForStat(item.color), 0.3)}`
                              : "none",
                        }}
                      />
                    ) : (
                      <Typography
                        variant="body1"
                        fontWeight="600"
                        sx={{
                          color:
                            item.color === "success"
                              ? themeColors.success
                              : item.color === "error"
                                ? themeColors.error
                                : item.color === "warning"
                                  ? themeColors.warning
                                  : item.color === "grey"
                                    ? themeColors.grey300
                                    : themeColors.grey100,
                        }}
                      >
                        {item.value}
                      </Typography>
                    )}
                  </Box>
                ))}
              </Stack>
            </GradientPaper>
          </Grid>
        </Grid>
      </DialogContent>

      <DialogActions sx={dialogActionsStyle}>
        <Button
          onClick={onClose}
          variant="outlined"
          sx={{
            borderRadius: 3,
            background: alpha(themeColors.grey800, 0.3),
            border: `1px solid ${alpha(themeColors.borderLight, 0.2)}`,
            color: themeColors.grey300,
            "&:hover": {
              background: alpha(themeColors.grey800, 0.5),
              borderColor: alpha(themeColors.borderLight, 0.3),
            },
          }}
        >
          Close
        </Button>
        <GradientButton sx={{ borderRadius: 3 }} startIcon={<AttachMoney />}>
          Place Bet
        </GradientButton>
      </DialogActions>
    </Dialog>
  );
};

export default SlipDetailModal;